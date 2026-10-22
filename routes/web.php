<?php

use App\Http\Controllers\ArenaController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Playground')->name('home');

Route::prefix('arena')->name('arena.')->group(function () {
    Route::post('join', [ArenaController::class, 'join'])->middleware('throttle:arena-join')->name('join');
    Route::post('update', [ArenaController::class, 'update'])->middleware('throttle:arena-update')->name('update');
    Route::post('leave', [ArenaController::class, 'leave'])->middleware('throttle:arena-leave')->name('leave');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
