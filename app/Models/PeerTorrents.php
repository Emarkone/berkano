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

    public function setDownloadAttribute($value)
    {
        $this->attributes['download'] = round($value/1048576);
    }

    public function setUploadAttribute($value)
    {
        $this->attributes['upload'] = round($value/1048576);
    }

    public function peer() 
    {
        return $this->hasOne('peer');
    }

    public function torrent() 
    {
        return $this->hasOne('torrent');
    }
}
