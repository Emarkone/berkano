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

        if(!$torrent) return \response('file unknown', 400);

        $user = User::where('uuid', '=', $id)->first();
        if(!$user) return \response('user unknown', 400);

        $peer = Peer::firstOrCreate(
            ['user_id' => $user->id, 'ip' => $request->ip(), 'port' => $request->get('port')]
        );
        
        PeerTorrents::firstOrCreate(
            ['peer_id' => $peer->id, 'torrent_id' => $torrent->id]
        );

        if($request->get('compact') !== '1') {
            $peers = $torrent->peers->map(function ($peer) {
                return collect($peer->toArray())
                ->only(['ip', 'port'])
                ->all();
            });
        } else {
            $peers = $torrent->peers->map(function ($peer) {
                $_ip = implode('',array_map(fn($value): string => dechex($value), explode('.',$peer['ip'])));
                $_port = dechex($peer['port']);
                return $_ip.$_port;
            });
        }

        $response = Bencode::encode(array("peers" => $peers, "interval" => 60));
        

        return response()->view('tracker', ['bencode' => $response]);
    }
}
   