<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeerTorrents extends Model
{
    protected $table = 'peer_torrent';

    use HasFactory;

    protected $fillable = [
        'peer_id',
        'torrent_id',
        'is_leeching',
        'download',
        'upload'
    ];

    protected $cast = [
        'is_leeching' => 'boolean'
    ];

    public function peer() 
    {
        return $this->hasOne('peer');
    }

    public function torrent() 
    {
        return $this->hasOne('torrent');
    }
}
