<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Peer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'peer_id',
        'ip',
        'port',
        'expire'
    ];

    protected $cast = [
        'is_active' => 'boolean'
    ];

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function torrents()
    {
        return $this->belongsToMany(Torrent::class, 'peer_torrent', 'peer_id', 'torrent_id');
    }

    public function peer_relations()
    {
        return $this->hasMany(PeerTorrents::class, 'peer_id', 'id');
    }
}
