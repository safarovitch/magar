<script lang="ts">
	import { onMount } from 'svelte';
	import { createArenaEcho } from '@/lib/arena-echo';

	type Orb = { x: number; y: number; diameter: number; visualDiameter: number; color: string; name?: string; score: number; targetX: number; targetY: number; vx?: number; vy?: number; splitVx?: number; splitVy?: number; isProjectile?: boolean; owner?: Orb; ownerClientId?: string; aiDecisionTimer?: number; clientId?: string };
	type Pellet = { x: number; y: number; color: string };
	type ContactDeformation = { angle: number; strength: number };
	type RoomCell = { x: number; y: number; diameter: number; score: number };
	type RoomPlayer = { client_id: string; name: string; color: string; x: number; y: number; diameter: number; score: number; cells?: RoomCell[] };
	type ArenaJoinResponse = { room_code: string; player: RoomPlayer; players: RoomPlayer[]; capacity: number };

	const WORLD_WIDTH = 8400;
	const WORLD_HEIGHT = 5400;
	const LEADERBOARD_SIZE = 5;
	const INITIAL_CAMERA_ZOOM = 2.2;
	const BASE_DRIFT_SPEED = 120;
	const PELLET_COUNT = 15_360;
	const PELLET_RESPAWN_DELAY = 20_000;
	const BOT_COUNT = 2;
	const PROJECTILE_SPEED = 320;
	const PROJECTILE_FRICTION = 1.4;
	const PROJECTILE_MIN_SPEED = 8;
	const PELLET_DIAMETER = 10;
	const DIAMETER_ANIMATION_SPEED = 130;
	const INITIAL_PLAYER_DIAMETER = 50;
	const MAX_PLAYER_CELLS = 50;
	const PELLET_COLORS = ['#8de6c1', '#76c8f3', '#f6cc73', '#d5a0fb', '#ff9a9a'];

	let canvas = $state<HTMLCanvasElement>();
	let arena = $state<HTMLElement>();
	let playerDiameter = $state(50);
	let eaten = $state(0);
	let isPaused = $state(false);
	let gameStarted = $state(false);
	let gameOver = $state(false);
	let playerNameDraft = $state('');
	let playerName = $state('');
	let roomCode = $state('');
	let roomCapacity = $state(50);
	let roomPlayerCount = $state(0);
	let networkError = $state('');
	let canvasWidth = $state(0);
	let canvasHeight = $state(0);
	let leaderboard = $state<{ name: string; score: number; color: string; isPlayer: boolean }[]>([]);

	let player: Orb = { x: WORLD_WIDTH / 2, y: WORLD_HEIGHT / 2, diameter: 50, visualDiameter: 50, color: '#a5f3cf', score: 0, targetX: WORLD_WIDTH / 2, targetY: WORLD_HEIGHT / 2 };
	let playerCells: Orb[] = [player];
	let pellets: Pellet[] = [];
	let respawningPellets: { color: string; respawnsAt: number }[] = [];
	let remotePlayers = $state<Orb[]>([]);
	let botPlayers = $state<Orb[]>([]);
	const projectiles: Orb[] = [];
	let clientId = '';
	let clientSecret = '';
	let realtime: ReturnType<typeof createArenaEcho> | undefined;
	let realtimeChannel: { stopListening: (event: string) => unknown } | undefined;
	let lastNetworkSync = 0;
	let networkSyncInFlight = false;
	let leavePromise: Promise<void> | undefined;
	let pointerX = 0;
	let pointerY = 0;
	let lastMoveAngle = 0;
	let pointerInitialized = false;
	let animationFrame = 0;
	let lastFrame = 0;
	let cameraZoom = INITIAL_CAMERA_ZOOM;

	function randomPellets(seedText = roomCode || 'magar-lobby'): Pellet[] {
		let seed = 2166136261;
		for (const character of seedText) seed = Math.imul(seed ^ character.charCodeAt(0), 16777619);
		const random = () => {
			seed += 0x6d2b79f5;
			let value = seed;
			value = Math.imul(value ^ (value >>> 15), value | 1);
			value ^= value + Math.imul(value ^ (value >>> 7), value | 61);
			return ((value ^ (value >>> 14)) >>> 0) / 4294967296;
		};
		return Array.from({ length: PELLET_COUNT }, () => ({
			x: 35 + random() * (WORLD_WIDTH - 70),
			y: 35 + random() * (WORLD_HEIGHT - 70),
			color: PELLET_COLORS[Math.floor(random() * PELLET_COLORS.length)],
		}));
	}

	function resetGame() {
		player.x = WORLD_WIDTH / 2;
		player.y = WORLD_HEIGHT / 2;
		player.targetX = player.x;
		player.targetY = player.y;
		player.splitVx = 0;
		player.splitVy = 0;
		player.diameter = 50;
		player.visualDiameter = 50;
		cameraZoom = INITIAL_CAMERA_ZOOM;
		player.score = 0;
		playerCells = [player];
		playerDiameter = 50;
		eaten = 0;
		projectiles.splice(0, projectiles.length);
		respawningPellets = [];
		botPlayers = [];
		lastMoveAngle = 0;
		pellets = randomPellets();
		updateLeaderboard();
	}

	function updateLeaderboard() {
		const remoteEntries = new Map<string, { name: string; score: number; color: string; isPlayer: boolean }>();
		for (const remote of remotePlayers) {
			const ownerId = remote.ownerClientId ?? remote.clientId ?? remote.name ?? 'remote';
			const entry = remoteEntries.get(ownerId) ?? { name: remote.name ?? 'player', score: 0, color: remote.color, isPlayer: false };
			entry.score += remote.score;
			remoteEntries.set(ownerId, entry);
		}
		leaderboard = [
			{ name: playerName || 'you', score: totalPlayerScore(), color: player.color, isPlayer: true },
			...remoteEntries.values(),
			...botPlayers.map((bot) => ({ name: bot.name ?? 'bot', score: bot.score, color: bot.color, isPlayer: false })),
		].sort((a, b) => b.score - a.score).slice(0, LEADERBOARD_SIZE);
	}

	function totalPlayerArea(): number {
		return playerCells.reduce((total, cell) => total + cell.diameter ** 2, 0);
	}

	function totalPlayerDiameter(): number {
		return Math.sqrt(totalPlayerArea());
	}

	function totalPlayerScore(): number {
		return playerCells.reduce((total, cell) => total + cell.score, 0);
	}

	function applyPlayerDiameter(diameter: number) {
		const currentDiameter = totalPlayerDiameter();
		if (currentDiameter <= 0) return;
		const scale = diameter / currentDiameter;
		for (const cell of playerCells) {
			cell.diameter *= scale;
			cell.visualDiameter *= scale;
		}
	}

	function getClientCredentials(): { id: string; secret: string } {
		const stored = localStorage.getItem('magar:identity');
		if (stored) {
			try {
				const identity = JSON.parse(stored) as { id?: string; secret?: string };
				if (typeof identity.id === 'string' && typeof identity.secret === 'string') {
					return { id: identity.id, secret: identity.secret };
				}
			} catch {
				localStorage.removeItem('magar:identity');
			}
		}
		const identity = { id: crypto.randomUUID(), secret: crypto.randomUUID() };
		localStorage.setItem('magar:identity', JSON.stringify(identity));
		return identity;
	}

	async function postArena(path: string, data: Record<string, unknown>) {
		const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
		const response = await fetch(`/arena/${path}`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
			body: JSON.stringify(data),
		});
		const result = await response.json();
		if (!response.ok) throw new Error(result.message ?? 'Could not connect to the arena.');
		return result;
	}

	function applyRoomState(players: RoomPlayer[], initial = false) {
		const ownPlayer = players.find((entry) => entry.client_id === clientId);
		if (!ownPlayer && gameStarted) {
			gameStarted = false;
			gameOver = true;
			isPaused = false;
			realtime?.leave(`arena.${roomCode}`);
			leavePromise = leaveCurrentRoom();
			return;
		}
		if (ownPlayer) {
			applyPlayerDiameter(ownPlayer.diameter);
			player.score += ownPlayer.score - totalPlayerScore();
			if (initial) {
				player.x = ownPlayer.x;
				player.y = ownPlayer.y;
				player.visualDiameter = ownPlayer.diameter;
				player.color = ownPlayer.color;
			}
		}
		const others = players.filter((entry) => entry.client_id !== clientId);
		const previous = new Map(remotePlayers.map((entry) => [entry.clientId, entry]));
		remotePlayers = others.flatMap((entry) => {
			const cells = entry.cells?.length ? entry.cells : [{ x: entry.x, y: entry.y, diameter: entry.diameter, score: entry.score }];
			return cells.map((cell, index) => {
				const cellId = `${entry.client_id}:${index}`;
				const existing = previous.get(cellId);
				return {
					x: existing?.x ?? cell.x,
					y: existing?.y ?? cell.y,
					targetX: cell.x,
					targetY: cell.y,
					diameter: cell.diameter,
					visualDiameter: existing?.visualDiameter ?? cell.diameter,
					color: entry.color,
					name: index === 0 ? entry.name : undefined,
					score: cell.score,
					clientId: cellId,
					ownerClientId: entry.client_id,
				};
			});
		});
		botPlayers = others.length === 0 ? (botPlayers.length === BOT_COUNT ? botPlayers : createBots()) : [];
		roomPlayerCount = players.length;
		updateLeaderboard();
	}

	function connectToRoom(code: string) {
		if (!realtime) realtime = createArenaEcho();
		realtimeChannel?.stopListening('.arena.state');
		realtimeChannel = realtime.channel(`arena.${code}`).listen('.arena.state', (event: { players: RoomPlayer[] }) => {
			applyRoomState(event.players);
		});
	}

	async function leaveCurrentRoom() {
		if (!roomCode || !clientId || !clientSecret) return;
		const code = roomCode;
		realtime?.leave(`arena.${code}`);
		roomCode = '';
		remotePlayers = [];
		botPlayers = [];
		roomPlayerCount = 0;
		try {
			await postArena('leave', { room_code: code, client_id: clientId, client_secret: clientSecret });
		} catch {
			// Stale seats are released automatically after the heartbeat timeout.
		}
	}

	async function syncRoom(timestamp: number) {
		if (!gameStarted || !roomCode || networkSyncInFlight || timestamp - lastNetworkSync < 150) return;
		lastNetworkSync = timestamp;
		networkSyncInFlight = true;
		try {
			const result = await postArena('update', {
				room_code: roomCode,
				client_id: clientId,
				client_secret: clientSecret,
				x: player.x,
				y: player.y,
				diameter: totalPlayerDiameter(),
				score: totalPlayerScore(),
				cells: playerCells.map(({ x, y, diameter, score }) => ({ x, y, diameter, score })),
			}) as { players: RoomPlayer[]; eliminated: boolean };
			if (result.eliminated) {
				gameStarted = false;
				gameOver = true;
				isPaused = false;
				realtime?.leave(`arena.${roomCode}`);
				return;
			}
			applyRoomState(result.players);
			networkError = '';
		} catch (error) {
			networkError = error instanceof Error ? error.message : 'Connection to the arena was interrupted.';
		} finally {
			networkSyncInFlight = false;
		}
	}

	async function startGame(event: SubmitEvent) {
		event.preventDefault();
		await leavePromise;
		const name = playerNameDraft.trim();
		if (!name) return;
		networkError = '';
		try {
			const identity = getClientCredentials();
			clientId = identity.id;
			clientSecret = identity.secret;
			const result = await postArena('join', { client_id: clientId, client_secret: clientSecret, name }) as ArenaJoinResponse;
			playerName = name;
			player.name = name;
			roomCode = result.room_code;
			roomCapacity = result.capacity;
			isPaused = false;
			gameOver = false;
			gameStarted = true;
			resetGame();
			applyRoomState(result.players, true);
			connectToRoom(roomCode);
		} catch (error) {
			networkError = error instanceof Error ? error.message : 'Could not connect to the arena.';
		}
	}

	function showStartDialog() {
		gameStarted = false;
		gameOver = true;
		isPaused = false;
		resetGame();
		gameOver = true;
		leavePromise = leaveCurrentRoom();
	}

	function resizeCanvas() {
		if (!canvas || !arena) return;
		const bounds = arena.getBoundingClientRect();
		const pixelRatio = window.devicePixelRatio || 1;
		canvasWidth = bounds.width;
		canvasHeight = bounds.height;
		if (!pointerInitialized) {
			pointerX = bounds.width / 2;
			pointerY = bounds.height / 2;
		}
		canvas.width = Math.round(bounds.width * pixelRatio);
		canvas.height = Math.round(bounds.height * pixelRatio);
		const context = canvas.getContext('2d');
		context?.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
	}

	function setPointerTarget(event: PointerEvent) {
		if (!canvas) return;
		const bounds = canvas.getBoundingClientRect();
		pointerX = event.clientX - bounds.left;
		pointerY = event.clientY - bounds.top;
		pointerInitialized = true;
	}

	function eatPellets(orb: Orb) {
		const radius = orb.diameter / 2;
		pellets = pellets.filter((pellet) => {
			const dx = pellet.x - orb.x;
			const dy = pellet.y - orb.y;
			const fullyContainsPellet = Math.hypot(dx, dy) + PELLET_DIAMETER / 2 <= radius;
			if (fullyContainsPellet && orb.diameter > PELLET_DIAMETER) {
				orb.diameter = Math.sqrt(orb.diameter ** 2 + PELLET_DIAMETER ** 2);
				orb.score += 10;
				if (playerCells.includes(orb)) {
					eaten += 1;
				}
				respawningPellets.push({ color: pellet.color, respawnsAt: Date.now() + PELLET_RESPAWN_DELAY });
				return false;
			}
			return true;
		});
	}

	function updateRespawningPellets() {
		const now = Date.now();
		for (let index = respawningPellets.length - 1; index >= 0; index -= 1) {
			const pellet = respawningPellets[index];
			if (pellet.respawnsAt > now) continue;
			pellets.push({
				x: 35 + Math.random() * (WORLD_WIDTH - 70),
				y: 35 + Math.random() * (WORLD_HEIGHT - 70),
				color: pellet.color,
			});
			respawningPellets.splice(index, 1);
		}
	}

	function createBots(): Orb[] {
		return Array.from({ length: BOT_COUNT }, (_, index) => {
			const angle = (index / BOT_COUNT) * Math.PI * 2;
			const x = WORLD_WIDTH / 2 + Math.cos(angle) * 650;
			const y = WORLD_HEIGHT / 2 + Math.sin(angle) * 650;
			return {
				x,
				y,
				diameter: 50,
				visualDiameter: 50,
				color: index === 0 ? '#f6cc73' : '#d5a0fb',
				name: `Bot ${index + 1}`,
				score: 0,
				targetX: x,
				targetY: y,
				aiDecisionTimer: 0,
			};
		});
	}

	function moveOrb(orb: Orb, destinationX: number, destinationY: number, speed: number, delta: number) {
		const dx = destinationX - orb.x;
		const dy = destinationY - orb.y;
		const distance = Math.hypot(dx, dy);
		if (distance < 2) return;
		const travel = Math.min(distance, speed * delta);
		orb.x = Math.max(orb.diameter / 2, Math.min(WORLD_WIDTH - orb.diameter / 2, orb.x + (dx / distance) * travel));
		orb.y = Math.max(orb.diameter / 2, Math.min(WORLD_HEIGHT - orb.diameter / 2, orb.y + (dy / distance) * travel));
	}

	function driftSpeed(orb: Orb) {
		return BASE_DRIFT_SPEED / Math.sqrt(orb.diameter / 50);
	}

	function splitLargestCell() {
		if (!gameStarted || playerCells.length >= MAX_PLAYER_CELLS) return;
		const cell = playerCells.reduce((largest, candidate) => candidate.diameter > largest.diameter ? candidate : largest);
		if (cell.diameter < INITIAL_PLAYER_DIAMETER * 2) return;

		const originalDiameter = cell.diameter;
		const originalX = cell.x;
		const originalY = cell.y;
		const newDiameter = INITIAL_PLAYER_DIAMETER;
		const remainingDiameter = Math.sqrt(originalDiameter ** 2 - newDiameter ** 2);
		const angle = lastMoveAngle;
		const directionX = Math.cos(angle);
		const directionY = Math.sin(angle);
		const originalScore = cell.score;
		cell.diameter = remainingDiameter;
		cell.score = Math.round(originalScore * (remainingDiameter ** 2 / originalDiameter ** 2));
		cell.splitVx = -directionX * 150;
		cell.splitVy = -directionY * 150;

		playerCells.push({
			x: Math.max(newDiameter / 2, Math.min(WORLD_WIDTH - newDiameter / 2, originalX + directionX * (originalDiameter / 2 + newDiameter / 2 + 2))),
			y: Math.max(newDiameter / 2, Math.min(WORLD_HEIGHT - newDiameter / 2, originalY + directionY * (originalDiameter / 2 + newDiameter / 2 + 2))),
			diameter: newDiameter,
			visualDiameter: newDiameter,
			color: player.color,
			name: player.name,
			score: originalScore - cell.score,
			targetX: cell.targetX,
			targetY: cell.targetY,
			splitVx: directionX * 420,
			splitVy: directionY * 420,
		});
	}

	function movePlayerCells(directionX: number, directionY: number, delta: number) {
		for (const cell of playerCells) {
			const boostX = cell.splitVx ?? 0;
			const boostY = cell.splitVy ?? 0;
			const boostSpeed = Math.hypot(boostX, boostY);
			const angle = boostSpeed > 0 ? Math.atan2(directionY * driftSpeed(cell) + boostY, directionX * driftSpeed(cell) + boostX) : Math.atan2(directionY, directionX);
			const speed = Math.hypot(directionX * driftSpeed(cell) + boostX, directionY * driftSpeed(cell) + boostY);
			moveOrb(cell, cell.x + Math.cos(angle) * 1000, cell.y + Math.sin(angle) * 1000, speed, delta);
			const decay = Math.exp(-3 * delta);
			cell.splitVx = boostX * decay;
			cell.splitVy = boostY * decay;
			if (Math.hypot(cell.splitVx, cell.splitVy) < 5) {
				cell.splitVx = 0;
				cell.splitVy = 0;
			}
			animateDiameter(cell, delta);
			eatPellets(cell);
		}
	}

	function shootMass() {
		const shooter = playerCells.reduce((largest, candidate) => candidate.diameter > largest.diameter ? candidate : largest);
		if (!gameStarted || shooter.diameter <= INITIAL_PLAYER_DIAMETER) return;
		const originalDiameter = shooter.diameter;
		const shotDiameter = originalDiameter * 0.1;
		const ownShots = projectiles.filter((projectile) => projectile.owner === shooter).length;
		const fanOffsets = [0, -1, 1, -2, 2];
		const directionAngle = lastMoveAngle + fanOffsets[ownShots % fanOffsets.length] * 0.24;
		const directionX = Math.cos(directionAngle);
		const directionY = Math.sin(directionAngle);
		shooter.diameter = originalDiameter - shotDiameter;
		const radius = shooter.diameter / 2;
		projectiles.push({
			x: shooter.x + (radius + shotDiameter / 2 + 2) * directionX,
			y: shooter.y + (radius + shotDiameter / 2 + 2) * directionY,
			diameter: shotDiameter,
			visualDiameter: shotDiameter,
			color: player.color,
			score: 0,
			targetX: shooter.x,
			targetY: shooter.y,
			vx: directionX * PROJECTILE_SPEED,
			vy: directionY * PROJECTILE_SPEED,
			isProjectile: true,
			owner: shooter,
		});
	}

	function updateProjectiles(delta: number) {
		for (const projectile of projectiles) {
			if (Math.hypot(projectile.vx ?? 0, projectile.vy ?? 0) < PROJECTILE_MIN_SPEED) {
				projectile.vx = 0;
				projectile.vy = 0;
				continue;
			}
			projectile.x += (projectile.vx ?? 0) * delta;
			projectile.y += (projectile.vy ?? 0) * delta;
			const friction = Math.exp(-PROJECTILE_FRICTION * delta);
			projectile.vx = (projectile.vx ?? 0) * friction;
			projectile.vy = (projectile.vy ?? 0) * friction;
			const speed = Math.hypot(projectile.vx, projectile.vy);
			if (speed < PROJECTILE_MIN_SPEED) {
				projectile.vx = 0;
				projectile.vy = 0;
			}
			const radius = projectile.diameter / 2;
			if (projectile.x < radius || projectile.x > WORLD_WIDTH - radius || projectile.y < radius || projectile.y > WORLD_HEIGHT - radius) {
				projectile.x = Math.max(radius, Math.min(WORLD_WIDTH - radius, projectile.x));
				projectile.y = Math.max(radius, Math.min(WORLD_HEIGHT - radius, projectile.y));
				projectile.vx = 0;
				projectile.vy = 0;
			}
			eatPellets(projectile);
		}
	}

	function handleKeyDown(event: KeyboardEvent) {
		if (event.repeat || !gameStarted) return;
		const target = event.target;
		if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement) return;
		if (event.code === 'Space') {
			event.preventDefault();
			splitLargestCell();
			return;
		}
		if (event.key.toLowerCase() !== 'w') return;
		event.preventDefault();
		shootMass();
	}

	function respawnRival(orb: Orb) {
		orb.diameter = 30 + Math.random() * 35;
		orb.visualDiameter = orb.diameter;
		orb.x = orb.diameter + Math.random() * (WORLD_WIDTH - orb.diameter * 2);
		orb.y = orb.diameter + Math.random() * (WORLD_HEIGHT - orb.diameter * 2);
		orb.targetX = 60 + Math.random() * (WORLD_WIDTH - 120);
		orb.targetY = 60 + Math.random() * (WORLD_HEIGHT - 120);
		orb.score = 0;
		orb.aiDecisionTimer = 0;
	}

	function updateBot(bot: Orb, delta: number) {
		bot.aiDecisionTimer = (bot.aiDecisionTimer ?? 0) - delta;
		const distanceToTarget = Math.hypot(bot.targetX - bot.x, bot.targetY - bot.y);
		if (bot.aiDecisionTimer <= 0 || distanceToTarget <= Math.max(40, bot.diameter * 0.75)) {
			const nearestPellet = pellets.reduce<{ pellet: Pellet; distance: number } | undefined>((nearest, pellet) => {
				const distance = Math.hypot(pellet.x - bot.x, pellet.y - bot.y);
				return !nearest || distance < nearest.distance ? { pellet, distance } : nearest;
			}, undefined);
			bot.targetX = nearestPellet?.pellet.x ?? 60 + Math.random() * (WORLD_WIDTH - 120);
			bot.targetY = nearestPellet?.pellet.y ?? 60 + Math.random() * (WORLD_HEIGHT - 120);
			bot.aiDecisionTimer = 0.35;
		}
		moveOrb(bot, bot.targetX, bot.targetY, driftSpeed(bot), delta);
		eatPellets(bot);
	}

	function resolveCellCollisions() {
		for (let firstIndex = 0; firstIndex < playerCells.length; firstIndex += 1) {
			const first = playerCells[firstIndex];
			for (let secondIndex = firstIndex + 1; secondIndex < playerCells.length;) {
				const second = playerCells[secondIndex];
				const distance = Math.hypot(second.x - first.x, second.y - first.y);
				if (distance > (first.diameter + second.diameter) / 2) {
					secondIndex += 1;
					continue;
				}

				const firstArea = first.diameter ** 2;
				const secondArea = second.diameter ** 2;
				const mergedArea = firstArea + secondArea;
				const mergedDiameter = Math.sqrt(mergedArea);
				first.splitVx = ((first.splitVx ?? 0) * firstArea + (second.splitVx ?? 0) * secondArea) / mergedArea;
				first.splitVy = ((first.splitVy ?? 0) * firstArea + (second.splitVy ?? 0) * secondArea) / mergedArea;
				first.diameter = mergedDiameter;
				first.score += second.score;
				first.visualDiameter = Math.min(first.visualDiameter, mergedDiameter);
				playerCells.splice(secondIndex, 1);
			}
		}
		if (!playerCells.includes(player) && playerCells.length > 0) player = playerCells[0];

		const rivals = [...remotePlayers, ...botPlayers];
		const cells = gameStarted ? [...playerCells, ...rivals, ...projectiles] : [...rivals, ...projectiles];
		for (let firstIndex = 0; firstIndex < cells.length; firstIndex += 1) {
			for (let secondIndex = firstIndex + 1; secondIndex < cells.length; secondIndex += 1) {
				const first = cells[firstIndex];
				const second = cells[secondIndex];
				if (!playerCells.includes(first) && !remotePlayers.includes(first) && !botPlayers.includes(first) && !projectiles.includes(first)) continue;
				if (!playerCells.includes(second) && !remotePlayers.includes(second) && !botPlayers.includes(second) && !projectiles.includes(second)) continue;
				if (playerCells.includes(first) && playerCells.includes(second)) continue;
				if (first.isProjectile && second.isProjectile && first.owner === second.owner) continue;
				if ((first.isProjectile && first.owner === second) || (second.isProjectile && second.owner === first)) continue;
				if ((first.isProjectile && !projectiles.includes(first)) || (second.isProjectile && !projectiles.includes(second))) continue;
				if ((first.clientId && !remotePlayers.some((remote) => remote.clientId === first.clientId)) || (second.clientId && !remotePlayers.some((remote) => remote.clientId === second.clientId))) continue;
				const distance = Math.hypot(second.x - first.x, second.y - first.y);
				let larger: Orb | undefined;
				let smaller: Orb | undefined;

				if (first.diameter > second.diameter && distance + second.diameter / 2 <= first.diameter / 2) {
					larger = first;
					smaller = second;
				} else if (second.diameter > first.diameter && distance + first.diameter / 2 <= second.diameter / 2) {
					larger = second;
					smaller = first;
				}

				if (!larger || !smaller) continue;
				larger.diameter = Math.sqrt(larger.diameter ** 2 + smaller.diameter ** 2);
				larger.visualDiameter = Math.min(larger.visualDiameter, larger.diameter);
				(larger.isProjectile ? larger.owner : larger)!.score += 100;

				if (playerCells.includes(smaller)) {
					playerCells = playerCells.filter((cell) => cell !== smaller);
					if (smaller === player && playerCells.length > 0) player = playerCells[0];
					if (playerCells.length === 0) {
						gameStarted = false;
						gameOver = true;
						isPaused = false;
						leavePromise = leaveCurrentRoom();
						return;
					}
					continue;
				}

				if (smaller.clientId) {
					remotePlayers = remotePlayers.filter((remote) => remote.ownerClientId !== smaller.ownerClientId);
					continue;
				}

				if (smaller.isProjectile) {
					projectiles.splice(projectiles.indexOf(smaller), 1);
				} else if (botPlayers.includes(smaller)) {
					respawnRival(smaller);
				} else {
					respawnRival(smaller);
				}
			}
		}
	}

	function drawOrb(context: CanvasRenderingContext2D, orb: Orb, screenX: number, screenY: number, isPlayer = false, time = 0, contact?: ContactDeformation) {
		const radius = orb.visualDiameter / 2;
		context.save();
		context.fillStyle = orb.color;
		context.beginPath();
		if (contact) {
			const points = 64;
			const maxBend = Math.min(4, radius * 0.09) * contact.strength;
			const contactWidth = 0.48;
			for (let index = 0; index <= points; index += 1) {
				const angle = (index / points) * Math.PI * 2;
				const angularDistance = Math.atan2(Math.sin(angle - contact.angle), Math.cos(angle - contact.angle));
				const influence = Math.exp(-(angularDistance ** 2) / (2 * contactWidth ** 2));
				const ripple = 1 + Math.sin(time * 0.008 + angularDistance * 9) * 0.12;
				const animatedRadius = radius - influence * maxBend * ripple;
				const x = screenX + Math.cos(angle) * animatedRadius;
				const y = screenY + Math.sin(angle) * animatedRadius;
				if (index === 0) context.moveTo(x, y);
				else context.lineTo(x, y);
			}
			context.closePath();
		} else {
			context.arc(screenX, screenY, radius, 0, Math.PI * 2);
		}
		context.fill();
		context.strokeStyle = isPlayer ? '#ffffff' : '#ffffff80';
		context.lineWidth = isPlayer ? 2 : 1;
		context.stroke();
		if (orb.name) {
			const nameFontSize = Math.min(14, Math.max(8, radius * 0.36));
			const maxTextWidth = radius * 1.5;
			context.font = `600 ${nameFontSize}px Inter, ui-sans-serif, system-ui, sans-serif`;
			context.textAlign = 'center';
			context.textBaseline = 'middle';
			context.lineWidth = Math.max(1.5, nameFontSize * 0.2);
			context.strokeStyle = '#ffffffb3';
			context.strokeText(orb.name, screenX, screenY, maxTextWidth);
			context.fillStyle = '#14231d';
			context.fillText(orb.name, screenX, screenY, maxTextWidth);
		}
		context.restore();
	}

	function animateDiameter(orb: Orb, delta: number) {
		const difference = orb.diameter - orb.visualDiameter;
		if (difference === 0) return;
		const step = DIAMETER_ANIMATION_SPEED * delta;
		orb.visualDiameter += Math.sign(difference) * Math.min(Math.abs(difference), step);
	}

	function render(timestamp: number) {
		animationFrame = requestAnimationFrame(render);
		const delta = Math.min((timestamp - (lastFrame || timestamp)) / 1000, 0.04);
		lastFrame = timestamp;
		if (!canvas || isPaused) return;

		playerDiameter = Math.round(totalPlayerDiameter());

		const targetZoom = Math.max(0.4, Math.min(INITIAL_CAMERA_ZOOM, INITIAL_CAMERA_ZOOM * Math.pow(INITIAL_PLAYER_DIAMETER / Math.max(1, totalPlayerDiameter()), 0.65)));
		cameraZoom += (targetZoom - cameraZoom) * Math.min(1, delta * 2.4);
		const cameraX = player.x - canvasWidth / (2 * cameraZoom);
		const cameraY = player.y - canvasHeight / (2 * cameraZoom);
		if (gameStarted) {
			const targetX = cameraX + pointerX / cameraZoom;
			const targetY = cameraY + pointerY / cameraZoom;
			const moveX = targetX - player.x;
			const moveY = targetY - player.y;
			if (Math.hypot(moveX, moveY) >= 2) lastMoveAngle = Math.atan2(moveY, moveX);
			const directionLength = Math.hypot(moveX, moveY);
			movePlayerCells(directionLength >= 2 ? moveX / directionLength : 0, directionLength >= 2 ? moveY / directionLength : 0, delta);
		}
		for (const remote of remotePlayers) {
			animateDiameter(remote, delta);
			moveOrb(remote, remote.targetX, remote.targetY, driftSpeed(remote) * 1.5, delta);
		}
		for (const bot of botPlayers) {
			animateDiameter(bot, delta);
			updateBot(bot, delta);
		}
		updateRespawningPellets();
		updateProjectiles(delta);
		resolveCellCollisions();
		if (gameStarted) void syncRoom(timestamp);

		const contactByOrb = new Map<Orb, ContactDeformation>();
		for (const ownCell of playerCells) {
			for (const rival of [...remotePlayers, ...botPlayers]) {
				const dx = rival.x - ownCell.x;
				const dy = rival.y - ownCell.y;
				const distance = Math.hypot(dx, dy);
				const ownRadius = ownCell.visualDiameter / 2;
				const rivalRadius = rival.visualDiameter / 2;
				const overlap = ownRadius + rivalRadius - distance;
				if (overlap > 0) {
					const strength = Math.min(1, overlap / Math.max(1, Math.min(ownRadius, rivalRadius) * 0.35));
					contactByOrb.set(ownCell, { angle: Math.atan2(dy, dx), strength });
					contactByOrb.set(rival, { angle: Math.atan2(-dy, -dx), strength });
				}
			}
		}

		const context = canvas.getContext('2d');
		if (!context) return;
		context.clearRect(0, 0, canvasWidth, canvasHeight);

		context.fillStyle = '#101a20';
		context.fillRect(0, 0, canvasWidth, canvasHeight);
		context.save();
		context.translate(canvasWidth / 2, canvasHeight / 2);
		context.scale(cameraZoom, cameraZoom);
		context.translate(-player.x, -player.y);

		context.strokeStyle = 'rgba(165, 243, 207, 0.055)';
		context.lineWidth = 1;
		const gridSize = 48;
		for (let x = Math.floor(cameraX / gridSize) * gridSize; x < cameraX + canvasWidth / cameraZoom + gridSize; x += gridSize) {
			context.beginPath(); context.moveTo(x, cameraY); context.lineTo(x, cameraY + canvasHeight / cameraZoom); context.stroke();
		}
		for (let y = Math.floor(cameraY / gridSize) * gridSize; y < cameraY + canvasHeight / cameraZoom + gridSize; y += gridSize) {
			context.beginPath(); context.moveTo(cameraX, y); context.lineTo(cameraX + canvasWidth / cameraZoom, y); context.stroke();
		}

		for (const pellet of pellets) {
			context.fillStyle = pellet.color;
			context.beginPath();
			context.arc(pellet.x, pellet.y, PELLET_DIAMETER / 2, 0, Math.PI * 2);
			context.fill();
		}
		const visibleOrbs = [...remotePlayers, ...botPlayers, ...projectiles, ...(!gameOver && gameStarted ? playerCells : [])].sort((first, second) => first.visualDiameter - second.visualDiameter);
		for (const orb of visibleOrbs) {
			drawOrb(context, orb, orb.x, orb.y, playerCells.includes(orb), timestamp, contactByOrb.get(orb));
		}
		context.restore();

		updateLeaderboard();
	}

	onMount(() => {
		if (!arena || !canvas) return;
		resizeCanvas();
		window.addEventListener('keydown', handleKeyDown);
		const observer = new ResizeObserver(resizeCanvas);
		observer.observe(arena);
		animationFrame = requestAnimationFrame(render);
		return () => {
			cancelAnimationFrame(animationFrame);
			window.removeEventListener('keydown', handleKeyDown);
			if (roomCode && clientId && clientSecret) {
				const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
				void fetch('/arena/leave', {
					method: 'POST',
					credentials: 'same-origin',
					keepalive: true,
					headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
					body: JSON.stringify({ room_code: roomCode, client_id: clientId, client_secret: clientSecret }),
				});
			}
			realtime?.disconnect();
			observer.disconnect();
		};
	});
</script>

<svelte:head>
	<title>Magar — Arena</title>
	<meta name="description" content="A tiny-cell arena. Drift, grow, and outlast the crowd." />
</svelte:head>

<main class="game-shell">
	<section class="game-layout" aria-label="Game arena">
		<div class="arena-column">
			<div class="arena-wrap" bind:this={arena}>
				<canvas bind:this={canvas} onpointermove={setPointerTarget} aria-label="Move your cell by moving the pointer over the arena"></canvas>
				<div class="arena-hud">
					<div class="hud-pill"><span class="hud-label">MASS</span><span class="hud-value">{playerDiameter}<small> px</small></span></div>
					<span class="hud-separator"></span>
					<div class="hud-pill"><span class="hud-label">PELLETS</span><span class="hud-value">{eaten}<small> / {PELLET_COUNT}</small></span></div>
					<span class="split-hint">SPACE · SPLIT</span>
					<button class="icon-button" onclick={() => (isPaused = !isPaused)} aria-label={isPaused ? 'Resume game' : 'Pause game'}>{isPaused ? '▶' : 'Ⅱ'}</button>
					<button class="icon-button" onclick={showStartDialog} aria-label="Start over">↻</button>
				</div>
				<div class="arena-coordinates"><span class="coordinate-dot"></span> SECTOR 04 · {WORLD_WIDTH} × {WORLD_HEIGHT}</div>
				{#if !gameStarted && !isPaused}
					<div class="start-overlay">
						<form class="start-card" onsubmit={startGame}>
							<div class="start-mark">m</div>
							<div class="section-kicker">MAGAR · OPEN ARENA</div>
							<h1>{gameOver ? 'Ready for another round?' : 'Welcome to the arena'}</h1>
							<p>{gameOver ? 'You were absorbed. Pick a name and jump back in.' : 'Choose a name and join up to 49 other players in the shared arena.'}</p>
							{#if networkError}<p class="network-error" role="alert">{networkError}</p>{/if}
							<label for="player-name">YOUR NAME</label>
							<input id="player-name" bind:value={playerNameDraft} maxlength="18" placeholder="Enter a name" autocomplete="nickname" required />
							<button type="submit">{gameOver ? 'Play again' : 'Start playing'} <span>→</span></button>
						</form>
					</div>
				{/if}
				{#if gameStarted}
					<section class="rail-card leaderboard-card" aria-label="Leaderboard">
						<div class="section-heading"><div><div class="section-kicker">TOP 5 · ROOM {roomCode.slice(0, 4).toUpperCase()}</div><h2>Leaderboard</h2></div><span class="player-count">{roomPlayerCount} / {roomCapacity} <span>players</span></span></div>
						<ol class="leaderboard">
							{#each leaderboard as entry, index (entry.name)}
								<li class:current-player={entry.isPlayer}><span class="rank">{String(index + 1).padStart(2, '0')}</span><span class="mini-orb" style={`--orb-color: ${entry.color}`}></span><span class="entry-name">{entry.name}</span><span class="entry-score">{entry.score}</span></li>
							{/each}
						</ol>
					</section>
				{/if}
				{#if isPaused}
					<div class="pause-overlay"><div class="pause-card"><span class="pause-icon">Ⅱ</span><h2>Taking a breather?</h2><p>Your arena is paused. Pick up where you left off when you’re ready.</p><button onclick={() => (isPaused = false)}>Back to the arena <span>→</span></button></div></div>
				{/if}
			</div>
		</div>
	</section>
</main>

<style>
	:global(body), :global(html), :global(#app) { width: 100%; height: 100%; min-height: 0; margin: 0; overflow: hidden; }
	:global(body), :global(*) { box-sizing: border-box; }
	:global(body) { min-width: 320px; background: #101a20; color: #eef5f1; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
	.game-shell { width: 100vw; height: 100vh; height: 100dvh; overflow: hidden; background: #101a20; }
	.game-layout, .arena-column { width: 100%; height: 100%; }
	.game-layout { margin: 0; }
	.arena-wrap { position: fixed; inset: 0; width: 100vw; height: 100vh; height: 100dvh; overflow: hidden; background: #101a20; }
	.arena-wrap canvas { display: block; width: 100%; height: 100%; cursor: crosshair; touch-action: none; }
	.arena-hud { position: absolute; z-index: 2; top: 16px; left: 16px; display: flex; align-items: center; gap: 15px; padding: 11px 14px; border: 1px solid #ffffff18; border-radius: 12px; background: #0c1514c9; backdrop-filter: blur(12px); }
	.hud-pill { display: grid; gap: 3px; }
	.hud-label, .section-kicker { color: #9aa9a2; font-size: 9px; font-weight: 750; letter-spacing: 1.2px; }
	.hud-value { color: #f4fbf7; font-size: 17px; font-weight: 720; letter-spacing: -.5px; }
	.hud-value small { color: #a3b2ab; font-size: 10px; font-weight: 500; }
	.hud-separator { width: 1px; height: 25px; background: #ffffff1a; }
	.split-hint { color: #a5f3cf; font-size: 8px; font-weight: 750; letter-spacing: .8px; white-space: nowrap; }
	.icon-button { width: 30px; height: 30px; border: 1px solid #ffffff22; border-radius: 8px; background: #ffffff0d; color: #e5f2eb; cursor: pointer; font-size: 13px; }
	.leaderboard-card { position: absolute; z-index: 2; top: 16px; right: 16px; width: 230px; padding: 14px 16px; border: 1px solid #e4f5eb30; border-radius: 15px; background: #0c1514b8; backdrop-filter: blur(14px); box-shadow: 0 12px 34px #0003; }
	.section-heading { display: flex; align-items: end; justify-content: space-between; margin-bottom: 8px; }
	.section-heading h2 { margin: 6px 0 0; color: #f0f7f3; font-size: 14px; letter-spacing: -.3px; }
	.player-count { color: #dce9e2; font-size: 11px; font-weight: 700; }
	.player-count span { color: #9aa9a2; font-size: 9px; font-weight: 500; }
	.leaderboard { margin: 0; padding: 0; list-style: none; }
	.leaderboard li { display: flex; align-items: center; height: 30px; gap: 9px; padding: 0 8px; border: 1px solid transparent; border-radius: 8px; color: #b8c7c0; }
	.rank { width: 17px; color: #81918a; font-size: 9px; font-variant-numeric: tabular-nums; }
	.mini-orb { width: 11px; height: 11px; border-radius: 50%; background: var(--orb-color); }
	.entry-name { flex: 1; font-size: 11px; }
	.entry-score { color: #d4e2db; font-size: 10px; font-variant-numeric: tabular-nums; }
	.current-player { border-color: #a5f3cf40 !important; background: #a5f3cf14; }
	.current-player .rank, .current-player .entry-name, .current-player .entry-score { color: #a5f3cf; }
	.arena-coordinates { position: absolute; right: 16px; bottom: 16px; color: #a0b0a8; font-size: 9px; font-weight: 650; letter-spacing: .9px; }
	.coordinate-dot { display: inline-block; width: 5px; height: 5px; margin: 0 5px 1px 0; border-radius: 50%; background: #a5f3cf; }
	.start-overlay { position: absolute; z-index: 4; inset: 0; display: grid; place-items: center; padding: 20px; background: #09111140; }
	.start-card { width: min(390px, 100%); padding: 30px; border: 1px solid #d2eddf25; border-radius: 20px; background: #15201feF; box-shadow: 0 24px 90px #0008; }
	.start-mark { display: grid; place-items: center; width: 38px; height: 38px; margin-bottom: 22px; border-radius: 12px; background: #a5f3cf; color: #123026; font-size: 25px; font-weight: 800; }
	.start-card h1 { margin: 9px 0; color: #f0f7f3; font-size: 23px; letter-spacing: -.6px; }
	.start-card p { margin: 0 0 24px; color: #a2b0aa; font-size: 12px; line-height: 1.6; }
	.start-card label { display: block; margin-bottom: 8px; color: #a6b5ae; font-size: 9px; font-weight: 750; letter-spacing: 1px; }
	.start-card input { width: 100%; padding: 12px 13px; border: 1px solid #d2eddf28; border-radius: 10px; outline: none; background: #0b1414; color: #f0f7f3; font: inherit; font-size: 13px; }
	.start-card input:focus { border-color: #a5f3cf; box-shadow: 0 0 0 3px #a5f3cf20; }
	.start-card input::placeholder { color: #71817a; }
	.start-card button { display: flex; justify-content: center; align-items: center; gap: 10px; width: 100%; margin-top: 13px; padding: 12px; border: 0; border-radius: 10px; background: #a5f3cf; color: #123026; cursor: pointer; font-size: 12px; font-weight: 750; }
	.pause-overlay { position: absolute; z-index: 3; inset: 0; display: grid; place-items: center; background: #091111b8; backdrop-filter: blur(7px); }
	.pause-card { width: min(310px, calc(100% - 40px)); padding: 28px; border: 1px solid #d2eddf20; border-radius: 18px; background: #172222; text-align: center; box-shadow: 0 20px 70px #0008; }
	.pause-icon { display: inline-grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; background: #a5f3cf; color: #123026; font-size: 15px; }
	.pause-card h2 { margin: 17px 0 7px; font-size: 17px; }
	.pause-card p { margin: 0; color: #a2b0aa; font-size: 11px; line-height: 1.6; }
	.pause-card button { margin-top: 20px; padding: 10px 14px; border: 0; border-radius: 9px; background: #a5f3cf; color: #123026; cursor: pointer; font-size: 11px; font-weight: 700; }
	.pause-card button span { margin-left: 8px; }
	.network-error { margin: 0 0 12px !important; color: #ffaaa6 !important; }
	@media (max-width: 700px) {
		.arena-hud { top: 12px; left: 12px; gap: 10px; padding: 9px 10px; }
		.split-hint { display: none; }
		.leaderboard-card { top: 76px; right: 12px; width: min(210px, calc(100vw - 24px)); }
		.arena-coordinates { right: 12px; bottom: 12px; }
	}
</style>
