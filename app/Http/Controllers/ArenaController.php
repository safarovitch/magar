<?php

namespace App\Http\Controllers;

use App\Events\ArenaStateUpdated;
use App\Models\ArenaPlayer;
use App\Models\ArenaRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ArenaController extends Controller
{
    private const ROOM_CAPACITY = 50;

    private const ACTIVE_FOR_SECONDS = 45;

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:1', 'max:18'],
        ]);

        /** @var array{0: ArenaRoom, 1: ArenaPlayer} $result */
        $result = Cache::lock('arena:matchmaking', 10)->block(5, function () use ($data): array {
            return DB::transaction(function () use ($data) {
                $cutoff = now()->subSeconds(self::ACTIVE_FOR_SECONDS);
                ArenaPlayer::query()->where('last_seen', '<', $cutoff)->delete();

                $existing = ArenaPlayer::query()->where('client_id', $data['client_id'])->lockForUpdate()->first();
                if ($existing) {
                    $this->authorizePlayerSecret($existing, $data['client_secret']);
                    $existing->update([
                        'name' => $data['name'],
                        'x' => 4200,
                        'y' => 2700,
                        'diameter' => 50,
                        'score' => 0,
                        'cells' => [['x' => 4200, 'y' => 2700, 'diameter' => 50, 'score' => 0]],
                        'last_seen' => now(),
                    ]);

                    return [$existing->room, $existing];
                }

                $room = ArenaRoom::query()
                    ->whereRaw(
                        '(select count(*) from arena_players where arena_players.arena_room_id = arena_rooms.id and arena_players.last_seen >= ?) < ?',
                        [$cutoff, self::ROOM_CAPACITY],
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $room ??= ArenaRoom::query()->create(['code' => (string) Str::uuid()]);
                $index = ArenaPlayer::query()->where('arena_room_id', $room->id)->count();
                $colors = ['#a5f3cf', '#b9a0ff', '#ffad8a', '#7dd9f2', '#f18fbb', '#f6cc73', '#8de6c1', '#ff9a9a'];
                $player = $room->players()->create([
                    'client_id' => $data['client_id'],
                    'secret_hash' => hash('sha256', $data['client_secret']),
                    'name' => $data['name'],
                    'color' => $colors[$index % count($colors)],
                    'x' => 4200,
                    'y' => 2700,
                    'diameter' => 50,
                    'score' => 0,
                    'cells' => [['x' => 4200, 'y' => 2700, 'diameter' => 50, 'score' => 0]],
                    'last_seen' => now(),
                ]);

                return [$room, $player];
            });
        });

        [$room, $player] = $result;
        $players = $this->activePlayers($room);
        broadcast(new ArenaStateUpdated($room->code, $players));

        return response()->json([
            'room_code' => $room->code,
            'client_id' => $player->client_id,
            'player' => $this->serializePlayer($player),
            'players' => $players,
            'capacity' => self::ROOM_CAPACITY,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_code' => ['required', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['required', 'uuid'],
            'x' => ['required', 'numeric', 'between:0,8400'],
            'y' => ['required', 'numeric', 'between:0,5400'],
            'diameter' => ['required', 'numeric', 'between:10,10000'],
            'score' => ['required', 'integer', 'min:0', 'max:100000000'],
            'cells' => ['sometimes', 'array', 'max:50'],
            'cells.*.x' => ['required_with:cells', 'numeric', 'between:0,8400'],
            'cells.*.y' => ['required_with:cells', 'numeric', 'between:0,5400'],
            'cells.*.diameter' => ['required_with:cells', 'numeric', 'between:10,10000'],
            'cells.*.score' => ['required_with:cells', 'integer', 'min:0', 'max:100000000'],
        ]);

        /** @var array{room_code: string, players: array<int, array<string, mixed>>, eliminated: bool} $result */
        $result = DB::transaction(function () use ($data): array {
            $player = ArenaPlayer::query()
                ->where('client_id', $data['client_id'])
                ->whereHas('room', fn (Builder $query) => $query->where('code', $data['room_code']))
                ->lockForUpdate()
                ->firstOrFail();
            $this->authorizePlayerSecret($player, $data['client_secret']);

            $player->update([
                'x' => $data['x'],
                'y' => $data['y'],
                'diameter' => $data['diameter'],
                'score' => $data['score'],
                'cells' => $data['cells'] ?? $player->cells ?? [[
                    'x' => $data['x'],
                    'y' => $data['y'],
                    'diameter' => $data['diameter'],
                    'score' => $data['score'],
                ]],
                'last_seen' => now(),
            ]);

            $room = $player->room;
            $cutoff = now()->subSeconds(self::ACTIVE_FOR_SECONDS);
            ArenaPlayer::query()->where('arena_room_id', $room->id)->where('last_seen', '<', $cutoff)->delete();
            $players = ArenaPlayer::query()->where('arena_room_id', $room->id)->where('last_seen', '>=', $cutoff)->lockForUpdate()->get();
            $eliminatedIds = [];

            for ($firstIndex = 0; $firstIndex < $players->count(); $firstIndex++) {
                for ($secondIndex = $firstIndex + 1; $secondIndex < $players->count(); $secondIndex++) {
                    $first = $players[$firstIndex];
                    $second = $players[$secondIndex];
                    if (in_array($first->id, $eliminatedIds, true) || in_array($second->id, $eliminatedIds, true)) {
                        continue;
                    }

                    $distance = hypot($second->x - $first->x, $second->y - $first->y);
                    $larger = null;
                    $smaller = null;
                    if ($first->diameter > $second->diameter && $distance + $second->diameter / 2 <= $first->diameter / 2) {
                        $larger = $first;
                        $smaller = $second;
                    } elseif ($second->diameter > $first->diameter && $distance + $first->diameter / 2 <= $second->diameter / 2) {
                        $larger = $second;
                        $smaller = $first;
                    }

                    if (! $larger || ! $smaller) {
                        continue;
                    }

                    $larger->diameter = sqrt($larger->diameter ** 2 + $smaller->diameter ** 2);
                    $larger->score += 100;
                    $larger->save();
                    $smaller->delete();
                    $eliminatedIds[] = $smaller->id;
                }
            }

            return [
                'room_code' => $room->code,
                'players' => $this->activePlayers($room),
                'eliminated' => in_array($player->id, $eliminatedIds, true),
            ];
        });

        broadcast(new ArenaStateUpdated($result['room_code'], $result['players']));

        return response()->json($result);
    }

    public function leave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_code' => ['required', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['required', 'uuid'],
        ]);

        $room = ArenaRoom::query()->where('code', $data['room_code'])->firstOrFail();
        $player = $room->players()->where('client_id', $data['client_id'])->firstOrFail();
        $this->authorizePlayerSecret($player, $data['client_secret']);
        $player->delete();
        $players = $this->activePlayers($room);
        broadcast(new ArenaStateUpdated($room->code, $players));

        return response()->json(['players' => $players]);
    }

    /** @return array<int, array<string, mixed>> */
    /** @return array<int, array<string, mixed>> */
    private function activePlayers(ArenaRoom $room): array
    {
        /** @var Collection<int, ArenaPlayer> $players */
        $players = $room->players()
            ->where('last_seen', '>=', now()->subSeconds(self::ACTIVE_FOR_SECONDS))
            ->get();

        return $players->map(fn (ArenaPlayer $player) => $this->serializePlayer($player))->all();
    }

    /** @return array<string, mixed> */
    private function serializePlayer(ArenaPlayer $player): array
    {
        return [
            'client_id' => $player->client_id,
            'name' => $player->name,
            'color' => $player->color,
            'x' => $player->x,
            'y' => $player->y,
            'diameter' => $player->diameter,
            'score' => $player->score,
            'cells' => $player->cells ?? [[
                'x' => $player->x,
                'y' => $player->y,
                'diameter' => $player->diameter,
                'score' => $player->score,
            ]],
        ];
    }

    private function authorizePlayerSecret(ArenaPlayer $player, string $secret): void
    {
        if (! hash_equals($player->secret_hash, hash('sha256', $secret))) {
            throw ValidationException::withMessages(['client_id' => 'This game session is no longer valid. Refresh and try again.']);
        }
    }
}
