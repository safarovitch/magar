<?php

namespace Tests\Feature;

use App\Events\ArenaStateUpdated;
use App\Models\ArenaPlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArenaMultiplayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_players_share_rooms_with_a_maximum_of_fifty_players(): void
    {
        Event::fake([ArenaStateUpdated::class]);
        $roomCodes = [];

        for ($index = 0; $index < 51; $index++) {
            $response = $this->postJson('/arena/join', $this->joinPayload("player-{$index}"));
            $response->assertOk();
            $roomCodes[] = $response->json('room_code');
        }

        self::assertSame($roomCodes[0], $roomCodes[49]);
        self::assertNotSame($roomCodes[0], $roomCodes[50]);
        self::assertSame(50, ArenaPlayer::query()->whereHas('room', fn ($query) => $query->where('code', $roomCodes[0]))->count());
        self::assertSame(1, ArenaPlayer::query()->whereHas('room', fn ($query) => $query->where('code', $roomCodes[50]))->count());
    }

    public function test_a_player_can_rejoin_after_leaving_a_game(): void
    {
        Event::fake([ArenaStateUpdated::class]);
        $payload = $this->joinPayload('repeat-player');

        $firstJoin = $this->postJson('/arena/join', $payload)->assertOk()->json();
        self::assertSame(4200, $firstJoin['player']['x']);
        self::assertSame(2700, $firstJoin['player']['y']);
        $this->postJson('/arena/leave', [
            'room_code' => $firstJoin['room_code'],
            'client_id' => $payload['client_id'],
            'client_secret' => $payload['client_secret'],
        ])->assertOk();

        $secondJoin = $this->postJson('/arena/join', $payload)->assertOk()->json();

        self::assertSame(4200, $secondJoin['player']['x']);
        self::assertSame(2700, $secondJoin['player']['y']);
        self::assertSame(1, ArenaPlayer::query()->where('client_id', $payload['client_id'])->count());
    }

    public function test_player_updates_are_returned_as_shared_room_state(): void
    {
        Event::fake([ArenaStateUpdated::class]);
        $first = $this->joinPayload('alpha');
        $second = $this->joinPayload('bravo');
        $firstJoin = $this->postJson('/arena/join', $first)->assertOk()->json();
        $secondJoin = $this->postJson('/arena/join', $second)->assertOk()->json();

        self::assertSame($firstJoin['room_code'], $secondJoin['room_code']);

        $response = $this->postJson('/arena/update', [
            'room_code' => $firstJoin['room_code'],
            'client_id' => $first['client_id'],
            'client_secret' => $first['client_secret'],
            'x' => 1400,
            'y' => 900,
            'diameter' => 70,
            'score' => 120,
            'cells' => [
                ['x' => 1400, 'y' => 900, 'diameter' => 50, 'score' => 60],
                ['x' => 1460, 'y' => 900, 'diameter' => 50, 'score' => 60],
            ],
        ])->assertOk();

        self::assertCount(2, $response->json('players'));
        self::assertEquals(1400, $response->json('players.0.x'));
        self::assertCount(2, $response->json('players.0.cells'));
        Event::assertDispatched(ArenaStateUpdated::class);
    }

    public function test_a_client_secret_is_required_to_update_a_player(): void
    {
        Event::fake([ArenaStateUpdated::class]);
        $payload = $this->joinPayload('secret-test');
        $join = $this->postJson('/arena/join', $payload)->assertOk()->json();

        $this->postJson('/arena/update', [
            'room_code' => $join['room_code'],
            'client_id' => $payload['client_id'],
            'client_secret' => (string) Str::uuid(),
            'x' => 1400,
            'y' => 900,
            'diameter' => 50,
            'score' => 0,
        ])->assertUnprocessable();
    }

    /** @return array{client_id: string, client_secret: string, name: string} */
    private function joinPayload(string $name): array
    {
        return [
            'client_id' => (string) Str::uuid(),
            'client_secret' => (string) Str::uuid(),
            'name' => $name,
        ];
    }
}
