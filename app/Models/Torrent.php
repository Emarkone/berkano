<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Torrent extends Model
{
    use HasFactory;

    protected $with = ['peers'];

    public function peers()
    {
        return $this->belongsToMany(Peer::class, 'peer_torrent', 'torrent_id', 'peer_id');
    }

    public function creator()
    {
        return $this->hasOne(User::class);
    }
}
