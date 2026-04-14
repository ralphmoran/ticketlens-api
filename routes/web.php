<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->file(public_path('landing.html')));
Route::get('/inertia-test', fn () => inertia('Test'));

// LemonSqueezy webhook (public, HMAC-verified inside controller)
Route::post('/webhooks/lemonsqueezy', [\App\Http\Controllers\Console\LemonSqueezyWebhookController::class, 'handle']);

Route::prefix('console')->name('console.')->group(function () {
    // Auth (guest only)
    Route::middleware('guest')->group(function () {
        Route::get('/login', [\App\Http\Controllers\Console\AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [\App\Http\Controllers\Console\AuthController::class, 'login']);
    });

    Route::post('/logout', [\App\Http\Controllers\Console\AuthController::class, 'logout'])
        ->name('logout')
        ->middleware('auth');

    // Console root → redirect to dashboard
    Route::get('/', fn () => redirect()->route('console.dashboard'))->name('index');

    // Authenticated console routes
    Route::middleware('auth')->group(function () {
        // Dashboard — landing page after login
        Route::get('/dashboard', [\App\Http\Controllers\Console\DashboardController::class, 'index'])->name('dashboard');

        // Analytics — accessible to all authenticated users (Free shows teaser)
        Route::get('/analytics', [\App\Http\Controllers\Console\AnalyticsController::class, 'index'])->name('analytics');

        // Account — accessible to all authenticated users
        Route::get('/account', [\App\Http\Controllers\Console\AccountController::class, 'index'])->name('account');
        Route::post('/account/keys', [\App\Http\Controllers\Console\AccountController::class, 'updateKeys'])->name('account.keys');

        // Upgrade page — shown when permission is denied
        Route::get('/upgrade', [\App\Http\Controllers\Console\UpgradeController::class, 'index'])->name('upgrade');

        // Workflow modules (permission-gated)
        Route::get('/schedules', [\App\Http\Controllers\Console\SchedulesController::class, 'index'])
            ->middleware('permission:Schedules')->name('schedules');
        Route::get('/digests', [\App\Http\Controllers\Console\DigestsController::class, 'index'])
            ->middleware('permission:Digests')->name('digests');
        Route::get('/summarize', [\App\Http\Controllers\Console\SummarizeController::class, 'index'])
            ->middleware('permission:Summarize')->name('summarize');
        Route::get('/compliance', [\App\Http\Controllers\Console\ComplianceController::class, 'index'])
            ->middleware('permission:Compliance')->name('compliance');

        // Team management
        Route::get('/team', [\App\Http\Controllers\Console\TeamController::class, 'index'])
            ->middleware('permission:MultiAccount')->name('team');

        // Admin section
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/clients', [\App\Http\Controllers\Console\AdminController::class, 'clients'])
                ->middleware('permission:AdminUsers')->name('clients');
            Route::get('/licenses', [\App\Http\Controllers\Console\AdminController::class, 'licenses'])
                ->middleware('permission:AdminLicenses')->name('licenses');
            Route::get('/revenue', [\App\Http\Controllers\Console\AdminController::class, 'revenue'])
                ->middleware('permission:AdminRevenue')->name('revenue');
        });
    });
});
