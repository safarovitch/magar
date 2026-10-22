<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('code')->unique();
            $table->timestamps();
        });

        Schema::create('arena_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arena_room_id')->constrained('arena_rooms')->cascadeOnDelete();
            $table->uuid('client_id')->unique();
            $table->char('secret_hash', 64);
            $table->string('name', 18);
            $table->string('color', 7);
            $table->double('x');
            $table->double('y');
            $table->double('diameter')->default(50);
            $table->unsignedInteger('score')->default(0);
            $table->timestamp('last_seen')->index();
            $table->timestamps();

            $table->index(['arena_room_id', 'last_seen']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_players');
        Schema::dropIfExists('arena_rooms');
    }
};
