<?php

namespace App\Http\Controllers;

use App\Models\Peer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Torrent;
use App\Models\User;
use App\Models\PeerTorrents;
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

        $peer = Peer::firstOrCreate(
            ['user_id' => $user->id, 'ip' => $request->ip(), 'port' => $request->get('port')]
        );
    
        $leeching = ($request->get('left') != 0);

        $peerTorrent = PeerTorrents::firstOrCreate(
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
        
        if($request->get('compact') === '1') {
            $peers = $torrent->peers->map(function ($peer) {
                return collect($peer->toArray())
                ->only(['ip', 'port'])
                ->all();
            });
        } else {
            $peers = $torrent->peers->map(function ($peer) {
                $_ip = implode('',array_map(fn($value): string => substr("00".dechex($value),strlen(dechex($value)),2), explode('.',$peer['ip'])));
                $_port = substr("0000".dechex($peer['port']), strlen(dechex($peer['port'])), 4);
                return hex2bin($_ip.$_port);
            });
            $peers = implode('', $peers);
        }

        $leechers = PeerTorrents::where('leeching', '=', 'true')->where('torrent_id','=',$torrent->id)->get()->count();
        $seeders = PeerTorrents::where('leeching', '=', 'false')->where('torrent_id','=',$torrent->id)->get()->count();

        $response = Bencode::encode(array('peers' => $peers, 'complete' => $seeders, 'incomplete'=> $leechers, 'interval' => 600));

        return response($response, 200)
                  ->header('Content-Type', 'text/plain');
    }


    protected function failureResponse($reason) {
        $reason = Bencode::encode(array('failure reason' => $reason));
        return response($reason, 200)
                  ->header('Content-Type', 'text/plain');
    }
}
   