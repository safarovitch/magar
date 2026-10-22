<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArenaRoom extends Model
{
    /** @var list<string> */
    protected $fillable = ['code'];

    /** @return HasMany<ArenaPlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(ArenaPlayer::class);
    }
}
