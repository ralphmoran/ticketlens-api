<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(public_path('landing.html')));

Route::get('/inertia-test', fn () => inertia('Test'));

// Console routes
Route::prefix('console')->name('console.')->group(function () {
    // Auth (guest only)
    Route::middleware('guest')->group(function () {
        Route::get('/login', [\App\Http\Controllers\Console\AuthController::class, 'showLogin'])
            ->name('login');
        Route::post('/login', [\App\Http\Controllers\Console\AuthController::class, 'login']);
    });

    Route::post('/logout', [\App\Http\Controllers\Console\AuthController::class, 'logout'])
        ->name('logout')
        ->middleware('auth');

    // Authenticated console
    Route::middleware(['auth'])->group(function () {
        Route::get('/dashboard', fn () => Inertia\Inertia::render('Console/Dashboard'))
            ->name('dashboard');
    });
});
