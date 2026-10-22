<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArenaPlayer extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'arena_room_id',
        'client_id',
        'secret_hash',
        'name',
        'color',
        'x',
        'y',
        'diameter',
        'score',
        'cells',
        'last_seen',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
            'diameter' => 'float',
            'score' => 'integer',
            'cells' => 'array',
            'last_seen' => 'datetime',
        ];
    }

    /** @return BelongsTo<ArenaRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ArenaRoom::class, 'arena_room_id');
    }
}
