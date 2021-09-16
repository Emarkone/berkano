<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeerTorrents extends Model
{
    protected $table = 'peer_torrent';

    protected $with = ['peer'];

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

    public function setDownloadAttribute($value)
    {
        $this->attributes['download'] = round($value/1000000);
    }

    public function setUploadAttribute($value)
    {
        $this->attributes['upload'] = round($value/1000000);
    }

    public function peer() 
    {
        return $this->hasOne(Peer::class, 'id', 'peer_id');
    }

    public function torrent() 
    {
        return $this->hasOne(Torrent::class);
    }
}
