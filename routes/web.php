<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Clients (module 2) — read gated by view_clients, writes by edit_client (P3)
    Route::get('clients', [ClientController::class, 'index'])->middleware('can:view_clients')->name('clients.index');
    Route::get('clients/create', [ClientController::class, 'create'])->middleware('can:edit_client')->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])->middleware('can:edit_client')->name('clients.store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->middleware('can:view_clients')->name('clients.show');
    Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->middleware('can:edit_client')->name('clients.edit');
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('can:edit_client')->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('can:edit_client')->name('clients.destroy');
});

require __DIR__.'/auth.php';
