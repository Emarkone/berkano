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
    public function track(Request $request, $id)
    {

        if($request->get('test')) {
            $torrent = Torrent::where('hash', '=', $request->get('info_hash'))->first();
        } else {
            $torrent = Torrent::where('hash', '=', bin2hex($request->get('info_hash')))->first();
        }

        if(!$torrent) return $this->failureResponse('File unknown');

        $user = User::where('uuid', '=', $id)->first();
        if(!$user) return $this->failureResponse('User unknown');

        // Peer management
        $peer = Peer::where('user_id','=',$user->id)->where('ip','=',$this->getIp())->where('port','=',$request->get('port'));

        if($peer) {
            $peer->expire = Carbon::parse($peer->expire)->addMinutes(15);
        } else {
            $peer = Peer::create(
                ['user_id' => $user->id, 'ip' => $this->getIp(), 'port' => $request->get('port')]
            );
            $peer->expire = $peer->expire = Carbon::now()->addMinutes(15);
        }

        $peer->save();
        $leeching = ($request->get('left') != 0);

        PeerTorrents::firstOrCreate(
            ['peer_id' => $peer->id, 'torrent_id' => $torrent->id, 'leeching' => $leeching]
        );

        if($request->get('event') && !empty($request->get('event'))) {
            switch ($request->get('event')) {
                case 'stopped':
                    PeerTorrents::where('peer_id','=', $peer->id)->where('torrent_id', '=', $torrent->id)->delete();
                    $peer->delete();
                    break;
                default:
                    break;
            }
        }

        // Cleaning inactive peers
        $expired_peers = Peer::where('expire','<',Carbon::now())->get();
        foreach($expired_peers as $expired_peer) {
            PeerTorrents::where('peer_id', '=', $expired_peer->id)->delete();
            $expired_peer->delete();
        }

        // Peers delivery
        if($request->get('compact') != 1) {
            $peers = $torrent->peers->map(function ($peer) {
                return collect($peer->toArray())
                ->only(['ip', 'port'])
                ->all();
            });
        } else {
            $peers = hex2bin(implode($torrent->peers->map(function ($peer) {
                $ip = implode(array_map(fn($value): string => substr("00".dechex($value),strlen(dechex($value)),2), explode('.',$peer['ip'])));
                $port = substr("0000".dechex($peer['port']), strlen(dechex($peer['port'])), 4);
                return $ip.$port;
            })->toArray()));
        }

        // Global stats
        $leechers = PeerTorrents::where('leeching', '=', true)->where('torrent_id','=',$torrent->id)->get()->count();
        $seeders = PeerTorrents::where('leeching', '=', false)->where('torrent_id','=',$torrent->id)->get()->count();

        $response = Bencode::encode(array('peers' => $peers, 'complete' => $seeders, 'incomplete'=> $leechers, 'interval' => 600));

        return response($response, 200)
                ->header('Content-Type', 'text/plain');
    }


    protected function failureResponse($reason) {
        $reason = Bencode::encode(array('failure reason' => $reason));
        return response($reason, 200)
                ->header('Content-Type', 'text/plain');
    }

    protected function getIp(){
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key){
            if (array_key_exists($key, $_SERVER) === true){
                foreach (explode(',', $_SERVER[$key]) as $ip){
                    $ip = trim($ip); // just to be safe
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false){
                        return $ip;
                    }
                }
            }
        }
        return request()->ip(); // it will return server ip when no client ip found
    }
}
   