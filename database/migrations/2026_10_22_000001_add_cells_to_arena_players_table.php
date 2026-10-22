<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arena_players', function (Blueprint $table) {
            $table->json('cells')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('arena_players', function (Blueprint $table) {
            $table->dropColumn('cells');
        });
    }
};