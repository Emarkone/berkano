<?php

namespace App\Http\Controllers;

use App\Jobs\PeerDelivery;
use App\Models\Peer;
use Illuminate\Http\Request;
use App\Models\Torrent;
use App\Models\User;
use App\Models\PeerTorrents;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SandFox\Bencode\Bencode;

class TrackerController extends Controller
{
    public $interval = 1800;

    public $failure_reason;

    public $user;
    public $torrent;
    public $peer;
    public $peer_torrent;

    public $dirty = false;
    public $cache_hit = true;

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
        $this->peer = Peer::where('user_id', '=', $this->user->id)
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

        $this->peer->last_seen = now();
        $this->peer->is_active = true;

        // Roles assignement
        $this->peer_torrent = PeerTorrents::where('peer_id', '=', $this->peer->id)
            ->where('torrent_id', '=', $this->torrent->id)
            ->firstOr(
                function () {
                    $this->dirty = true;
                    return PeerTorrents::create(
                        ['peer_id' => $this->peer->id, 'torrent_id' => $this->torrent->id, 'is_leeching' => true, 'download' => 0, 'upload' => 0]
                    );
                }
            );

        $this->peer_torrent->is_leeching = ($request->get('left') != 0);
        $this->peer_torrent->download = $request->get('downloaded');
        $this->peer_torrent->upload = $request->get('uploaded');

        if ($request->get('event') && !empty($request->get('event'))) {

            switch ($request->get('event')) {
                case 'started':
                    $this->peer_torrent->is_leeching = true;
                    break;
                case 'stopped':
                    $this->peer->is_active = false;
                    $this->peer_torrent->is_leeching = false;
                    break;
                case 'completed':
                    $this->peer_torrent->is_leeching = false;
                    $this->torrent->completed++;
                    $this->torrent->save();
                    break;
                default:
                    break;
            }

            $this->dirty = true;
        }

        $this->peer_torrent->save();
        $this->peer->save();


        // Cleaning inactive peers
        if (
            Peer::where('is_active', '=', true)
            ->where('last_seen', '<', now()->subSeconds($this->interval * 1.5))
            ->update(['is_active' => false]) != 0
        ) {
            $this->dirty = true;
        };

        // Peers caching

        $response = null;

        while (!$response) {
            if ($this->peer_torrent->is_leeching) {
                if ($request->get('compact') == 1 && Cache::has('torrent_response_compact_leecher_' . $this->torrent->hash)) {
                    $response = Cache::get('torrent_response_compact_leecher_' . $this->torrent->hash);
                } elseif ($request->get('compact') == 0 && Cache::has('torrent_response_leecher_' . $this->torrent->hash)) {
                    $response = Cache::get('torrent_response_leecher_' . $this->torrent->hash);
                }
            } else {
                if ($request->get('compact') == 1 && Cache::has('torrent_response_compact_' . $this->torrent->hash)) {
                    $response = Cache::get('torrent_response_compact_' . $this->torrent->hash);
                } elseif ($request->get('compact') == 0 && Cache::has('torrent_response_' . $this->torrent->hash)) {
                    $response = Cache::get('torrent_response_' . $this->torrent->hash);
                }
            }

            if (!$response) {
                $this->stats();
                $infos = array('seeders' => $this->seeders, 'leechers' => $this->leechers, 'interval' => $this->interval, 'is_leeching' => $this->peer_torrent->is_leeching, 'compact' => ($request->get('compact') == 1));
                PeerDelivery::dispatchSync($this->torrent, $infos);
                $this->cache_hit = false;
            }
        }

        if ($this->dirty && $this->cache_hit) {
            $this->stats();
            $infos = array('seeders' => $this->seeders, 'leechers' => $this->leechers, 'interval' => $this->interval, 'is_leeching' => $this->peer_torrent->is_leeching, 'compact' => ($request->get('compact') == 1));
            PeerDelivery::dispatchAfterResponse($this->torrent, $infos);
        }

        return response($response, 200)
            ->header('Content-Type', 'text/plain');
    }

    public function scrape(Request $request, $id)
    {
        if (!$this->basicAuth($request, $id)) return $this->failureResponse();

        if (Cache::has('torrent_response_scrape_' . $this->torrent->hash)) {
            $response = Cache::get('torrent_response_scrape_' . $this->torrent->hash);
        } else {
            $this->stats();
            $response = Bencode::encode(array('file' => $request->get('info_hash'), 'complete' => $this->seeders, 'downloaded' => $this->completed, 'incomplete' => $this->leechers));
            Cache::put('torrent_response_scrape_' . $this->torrent->hash, $response, now()->addHour(2));
        }

        return response($response, 200)
            ->header('Content-Type', 'text/plain');
    }

    protected function stats()
    {
        $peer_torrents = PeerTorrents::where('torrent_id', '=', $this->torrent->id)
            ->whereRelation('peer', 'is_active', true)
            ->get();

        $this->leechers = $peer_torrents->filter(function ($torrent) {
            return ($torrent->is_leeching);
        })->count();

        $this->seeders  = $peer_torrents->reject(function ($torrent) {
            return ($torrent->is_leeching);
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
        if (request()->ip() == '192.168.1.1' || request()->ip() == '127.0.0.1') return env('EXTERNAL_IP');

        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip); // just to be safe
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        if ($ip == '192.168.1.1' || $ip == '127.0.0.1') return env('EXTERNAL_IP');
                        return $ip;
                    }
                }
            }
        }

        return request()->ip(); // it will return server ip when no client ip found
    }
}
