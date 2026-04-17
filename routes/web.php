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

    // Suspended account page (public — user is logged out before redirect)
    Route::get('/suspended', fn () => inertia('Console/Suspended'))->name('suspended');

    // Console root → redirect to dashboard
    Route::get('/', fn () => redirect()->route('console.dashboard'))->name('index');

    // Authenticated console routes
    Route::middleware('auth')->group(function () {
        // Stop impersonation — lives OUTSIDE the `owner` sub-group because during
        // impersonation the session is authed as the target (non-owner). The controller
        // checks the `impersonator_id` session key to authorise.
        Route::delete('/impersonate', [\App\Http\Controllers\Owner\ImpersonationController::class, 'destroy'])
            ->name('impersonate.stop');

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

        // Owner-only panel
        Route::prefix('owner')->name('owner.')->middleware('owner')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Owner\DashboardController::class, 'index'])->name('dashboard');

            // User management
            Route::get('/users', [\App\Http\Controllers\Owner\UserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [\App\Http\Controllers\Owner\UserController::class, 'show'])->name('users.show');
            Route::patch('/users/{user}', [\App\Http\Controllers\Owner\UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/suspend', [\App\Http\Controllers\Owner\UserController::class, 'suspend'])->name('users.suspend');
            Route::post('/users/{user}/restore', [\App\Http\Controllers\Owner\UserController::class, 'restore'])->name('users.restore');
            Route::delete('/users/{user}', [\App\Http\Controllers\Owner\UserController::class, 'destroy'])->name('users.destroy');

            // Audit log
            Route::get('/audit', [\App\Http\Controllers\Owner\AuditController::class, 'index'])->name('audit.index');

            // Tier→feature matrix
            Route::get('/tiers', [\App\Http\Controllers\Owner\TierController::class, 'index'])->name('tiers.index');
            Route::post('/tiers/{tier}/features', [\App\Http\Controllers\Owner\TierController::class, 'addFeature'])->name('tiers.features.add');
            Route::delete('/tiers/{tier}/features/{feature}', [\App\Http\Controllers\Owner\TierController::class, 'removeFeature'])->name('tiers.features.remove');

            // Feature grants
            Route::post('/users/{user}/grants', [\App\Http\Controllers\Owner\GrantController::class, 'store'])->name('grants.store');
            Route::delete('/users/{user}/grants/{grant}', [\App\Http\Controllers\Owner\GrantController::class, 'destroy'])->name('grants.destroy');

            // Impersonation — start only (stop lives outside this group, see above)
            Route::post('/impersonate/{user}', [\App\Http\Controllers\Owner\ImpersonationController::class, 'store'])->name('impersonate.start');
        });
    });
});
