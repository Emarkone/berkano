<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePeerTorrentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('peer_torrent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('torrent_id');
            $table->foreignId('peer_id');
            $table->unsignedBigInteger('download')->default(0);
            $table->unsignedBigInteger('upload')->default(0);
            $table->boolean('is_leeching')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('peer_torrent');
    }
}
