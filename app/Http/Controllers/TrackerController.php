<?php

namespace App\Http\Controllers;

use App\Models\Peer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Torrent;
use App\Models\User;
use App\Models\PeerTorrents;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use SandFox\Bencode\Bencode;

class TrackerController extends Controller
{
    public $interval = 1800;

    public $failure_reason;

    public $user;
    public $torrent;

    public $dirty = false;

    public $leechers;
    public $seeders;
    public $completed;

    public function basicAuth(Request $request, $id)
    {
        if ($request->get('test')) {
            $this->torrent = Torrent::where('hash', '=', $request->get('info_hash'))->first();
        } else {
            $this->torrent = Torrent::where('hash', '=', bin2hex($request->get('info_hash')))->first();
        }

        if (is_null($this->torrent)) {
            $this->failure_reason = 'file unknown';
            return false;
        }

        $this->user = User::where('uuid', '=', $id)->first();
        if (is_null($this->user)) {
            $this->failure_reason = 'user unknown';
            return false;
        }

        return true;
    }

    public function track(Request $request, $id)
    {

        if (!$this->basicAuth($request, $id)) return $this->failureResponse();

        // Peer management
        $peer = Peer::where('user_id', '=', $this->user->id)
            ->where('ip', '=', $this->getIp())
            ->where('port', '=', $request->get('port'))
            ->firstOr(
                function () use ($request) {
                    $this->dirty = true;
                    return Peer::create(
                        ['user_id' => $this->user->id, 'ip' => $this->getIp(), 'port' => $request->get('port')]
                    );
                }
            );

        $peer->last_seen = Carbon::now();
        $peer->is_active = true;

        // Roles assignement
        $peer_torrent = PeerTorrents::where('peer_id', '=', $peer->id)
            ->where('torrent_id', '=', $this->torrent->id)
            ->firstOr(
                function () use ($peer) {
                    $this->dirty = true;
                    return PeerTorrents::create(
                        ['peer_id' => $peer->id, 'torrent_id' => $this->torrent->id, 'is_leeching' => true, 'download' => 0, 'upload' => 0]
                    );
                }
            );

        $peer_torrent->is_leeching = ($request->get('left') != 0);
        $peer_torrent->download = $request->get('downloaded');
        $peer_torrent->upload = $request->get('uploaded');

        if ($request->get('event') && !empty($request->get('event'))) {

            switch ($request->get('event')) {
                case 'started':
                    $peer_torrent->is_leeching = true;
                    break;
                case 'stopped':
                    $peer->is_active = false;
                    $peer_torrent->is_leeching = false;
                    break;
                case 'completed':
                    $peer_torrent->is_leeching = false;
                    $this->torrent->completed++;
                    $this->torrent->save();
                    break;
                default:
                    break;
            }

            $this->dirty = true;
        }

        $peer_torrent->save();
        $peer->save();


        // Cleaning inactive peers
        if (
            Peer::where('is_active', '=', true)
            ->where('last_seen', '<', Carbon::now()->subSeconds($this->interval * 1.5))
            ->update(['is_active' => false]) != 0
        ) {
            $this->dirty = true;
        };

        // Peers delivery
        if (!$this->dirty && $request->get('compact') == 1 && Cache::has('torrent_response_compact_' . $this->torrent->hash)) {
            $response = Cache::get('torrent_response_compact_' . $this->torrent->hash);
        } elseif (!$this->dirty && $request->get('compact') != 1 && Cache::has('torrent_response_' . $this->torrent->hash)) {
            $response = Cache::get('torrent_response_' . $this->torrent->hash);
        } else {
            $this->stats();

            if ($peer_torrent->is_leeching) {
                $peers = PeerTorrents::where('torrent_id', '=', $this->torrent->id)->get();
            } else {
                $peers = PeerTorrents::where('torrent_id', '=', $this->torrent->id)->where('is_leeching', '=', true)->get();
            }

            $peers = collect($peers->pluck('peer'));

            $peers = $peers->filter(function ($peer) {
                return $peer->is_active;
            })
                ->reject(function ($peer) use ($request) {
                    return ($peer->ip == $this->getIp() && $peer->port == $request->get('port'));
                });

            if ($request->get('compact') != 1) {
                $peers = $peers->map(function ($peer) {
                    return collect($peer->toArray())
                        ->only(['ip', 'port'])
                        ->all();
                });
            } else {
                $peers = hex2bin(implode(
                    $peers->map(function ($peer) {
                        $ip = implode(array_map(fn ($value): string => substr('00' . dechex($value), strlen(dechex($value)), 2), explode('.', $peer['ip'])));
                        $port = substr('0000' . dechex($peer['port']), strlen(dechex($peer['port'])), 4);
                        return $ip . $port;
                    })->toArray()
                ));
            }

            $response = Bencode::encode(array('peers' => $peers, 'complete' => $this->seeders, 'incomplete' => $this->leechers, 'interval' => $this->interval));

            ($request->get('compact') == 1) ? Cache::put('torrent_response_compact_' . $this->torrent->hash, $response, $this->interval * 3) : Cache::put('torrent_response_' . $this->torrent->hash, $response, $this->interval * 3);
        }

        return response($response, 200)
            ->header('Content-Type', 'text/plain');
    }

    public function scrape(Request $request, $id)
    {
        if (!$this->basicAuth($request, $id)) return $this->failureResponse();

        $this->stats();

        $response = Bencode::encode(array('file' => $request->get('info_hash'), 'complete' => $this->seeders, 'downloaded' => $this->completed, 'incomplete' => $this->leechers));

        return response($response, 200)
            ->header('Content-Type', 'text/plain');
    }

    protected function stats()
    {
        $peer_torrents = PeerTorrents::where('torrent_id', '=', $this->torrent->id)->get();

        $this->leechers = $peer_torrents->filter(function ($torrent) {
            return ($torrent->is_leeching && $torrent->peer->is_active);
        })->count();

        $this->seeders  = $peer_torrents->reject(function ($torrent) {
            return ($torrent->is_leeching && $torrent->peer->is_active);
        })->count();

        $this->completed = $this->torrent->completed;
    }

    protected function failureResponse()
    {
        $reason = Bencode::encode(array('failure reason' => $this->failure_reason));
        return response($reason, 200)
            ->header('Content-Type', 'text/plain');
    }

    protected function getIp()
    {
        if (request()->ip() == '192.168.1.1' || request()->ip() == '127.0.0.1') return file_get_contents("http://ipecho.net/plain");

        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip); // just to be safe
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        if ($ip == '192.168.1.1' || $ip == '127.0.0.1') return file_get_contents("http://ipecho.net/plain");
                        return $ip;
                    }
                }
            }
        }
        
        return request()->ip(); // it will return server ip when no client ip found
    }
}
