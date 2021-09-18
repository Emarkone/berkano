<?php

namespace App\Jobs;

use App\Models\Peer;
use App\Models\Torrent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use SandFox\Bencode\Bencode;

class PeerDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    protected $torrent;
    protected $infos;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Torrent $torrent, Array $infos)
    {
        $this->torrent = $torrent;
        $this->infos = $infos;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->infos['is_leeching']) {
            $peers = Peer::whereRelation('peer_relations', 'torrent_id', $this->torrent->id)
                ->where('is_active', '=', true)
                ->orderBy('last_seen', 'DESC')
                ->limit(30)
                ->get();
        } else {
            $peers = Peer::whereRelation('peer_relations', 'torrent_id', $this->torrent->id)
                ->whereRelation('peer_relations', 'is_leeching', true)
                ->where('is_active', '=', true)
                ->orderBy('last_seen', 'DESC')
                ->limit(30)
                ->get();
        }

        if (!$this->infos['compact']) {
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

        $response = Bencode::encode(array('peers' => $peers, 'complete' => $this->infos['seeders'], 'incomplete' => $this->infos['leechers'], 'interval' => $this->infos['interval']));

        ($this->infos['compact'])
            ? Cache::put(($this->infos['is_leeching']) ? 'torrent_response_compact_leecher_' . $this->torrent->hash : 'torrent_response_compact_' . $this->torrent->hash, $response)
            : Cache::put(($this->infos['is_leeching']) ? 'torrent_response_leecher_' . $this->torrent->hash : 'torrent_response_' . $this->torrent->hash, $response);
    }
}
