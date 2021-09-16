<?php

namespace App\Http\Controllers;

use App\Models\Peer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Torrent;
use App\Models\User;
use App\Models\PeerTorrents;
use Carbon\Carbon;
use SandFox\Bencode\Bencode;

class TrackerController extends Controller
{
    public $interval = 1800;
    
    public $failure_reason;

    public $user;
    public $torrent;

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

        if(!$this->basicAuth($request,$id)) return $this->failureResponse();

        $this->stats();

        // Peer management
        $peer = Peer::where('user_id', '=', $this->user->id)->where('ip', '=', $this->getIp())->where('port', '=', $request->get('port'))->firstOr(function() use ($request) {
            return Peer::create(
                ['user_id' => $this->user->id, 'ip' => $this->getIp(), 'port' => $request->get('port')]
            );
        });

        $peer->last_seen = Carbon::now();
        $peer->is_active = true;

        // Roles assignement
        $peer_torrent = PeerTorrents::where('peer_id', '=', $peer->id)->where('torrent_id', '=', $this->torrent->id)->firstOr(
            function() use ($peer) {
             return PeerTorrents::create(
                ['peer_id' => $peer->id, 'torrent_id' => $this->torrent->id, 'is_leeching' => false, 'download' => 0, 'upload' => 0]
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
                case 'stopped':
                    $peer->is_active = false;
                    break;
                case 'completed':
                    $peer_torrent->is_leeching = false;
                    $this->torrent->completed++;
                    $this->torrent->save();
                default:
                    break;
            }
        }

        $peer_torrent->save();
        $peer->save();

        // Shortcut if already downloaded
        if ($request->get('left') == 0) return $this->successResponse(array('complete' => $this->seeders, 'incomplete' => $this->leechers, 'interval' => $this->interval));

        // Cleaning inactive peers
        $inactive_peers = Peer::where('last_seen', '<', Carbon::now()->subSeconds($this->interval*1.5))->get();
        foreach ($inactive_peers as $inactive_peer) {
            $inactive_peer->is_active = false;
        }

        // Peers delivery
        $peers = $this->torrent->peers
            ->filter(function ($peer) {
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
                    $ip = implode(array_map(fn ($value): string => substr("00" . dechex($value), strlen(dechex($value)), 2), explode('.', $peer['ip'])));
                    $port = substr("0000" . dechex($peer['port']), strlen(dechex($peer['port'])), 4);
                    return $ip . $port;
                })->toArray()
            ));
        }

        return $this->successResponse(array('peers' => $peers, 'complete' => $this->seeders, 'incomplete' => $this->leechers, 'interval' => $this->interval));
    }

    public function scrape(Request $request, $id)
    {
        if(!$this->basicAuth($request,$id)) return $this->failureResponse();

        $this->stats();

        return $this->successResponse(array('file' => $request->get('info_hash'), 'complete' => $this->seeders, 'downloaded' => $this->completed, 'incomplete' => $this->leechers));
    }

    protected function stats()
    {
        $torrents = PeerTorrents::where('torrent_id', '=', $this->torrent->id)->get();

        $this->leechers = $torrents->filter(function ($torrent) {
            return $torrent->is_leeching;
        })->count();

        $this->seeders  = $torrents->reject(function ($torrent) {
            return $torrent->is_leeching;
        })->count();

        $this->completed = $this->torrent->completed;
    }


    protected function failureResponse()
    {
        $reason = Bencode::encode(array('failure reason' => $this->failure_reason));
        return response($reason, 200)
            ->header('Content-Type', 'text/plain');
    }

    protected function successResponse($response)
    {
        return response(Bencode::encode($response), 200)
            ->header('Content-Type', 'text/plain');
    }

    protected function getIp()
    {
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip); // just to be safe
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return request()->ip(); // it will return server ip when no client ip found
    }
}
