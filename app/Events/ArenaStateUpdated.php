<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArenaStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int, array<string, mixed>> $players */
    public function __construct(
        public string $roomCode,
        public array $players,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('arena.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'arena.state';
    }

    /** @return array{players: array<int, array<string, mixed>>} */
    public function broadcastWith(): array
    {
        return ['players' => $this->players];
    }
}
