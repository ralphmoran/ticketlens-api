<?php

use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SummarizeController;
use App\Http\Controllers\Api\Triage\CollisionsController;
use App\Http\Controllers\Api\Triage\PushController;
use App\Http\Controllers\Api\Triage\ShareController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Define named rate limiters
RateLimiter::for('api-global', fn(Request $r) => Limit::perMinute(120)->by($r->ip()));
RateLimiter::for('summarize',  fn(Request $r) => Limit::perMinute(10)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('schedule',   fn(Request $r) => Limit::perMinute(5)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('digest',      fn(Request $r) => Limit::perMinute(20)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('compliance',  fn(Request $r) => Limit::perMinute(10)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('triage',      fn(Request $r) => Limit::perMinute(30)->by($r->bearerToken() ?: $r->ip()));

// Public license activation/validation — no auth, rate-limited by IP
RateLimiter::for('license-act', fn(Request $r) => Limit::perMinute(10)->by($r->ip()));
Route::middleware(['throttle:api-global', 'throttle:license-act'])->group(function () {
    Route::post('/v1/licenses/activate', [\App\Http\Controllers\Api\LicenseActivationController::class, 'activate']);
    Route::post('/v1/licenses/validate', [\App\Http\Controllers\Api\LicenseActivationController::class, 'validate']);
});

// CLI sync — auth via CLI token (all tiers, no license required)
RateLimiter::for('profiles', fn(Request $r) => Limit::perMinute(30)->by($r->bearerToken() ?: $r->ip()));
Route::middleware(['throttle:api-global', 'auth.cli'])->group(function () {
    Route::get('/v1/profiles', \App\Http\Controllers\Api\ProfileSyncController::class)
        ->middleware('throttle:profiles')
        ->name('api.profiles');
});

Route::middleware(['throttle:api-global', 'auth.license'])->group(function () {
    Route::post('/v1/summarize',      [SummarizeController::class, 'handle'])->middleware('throttle:summarize');
    Route::post('/v1/schedule',       [ScheduleController::class, 'store'])->middleware('throttle:schedule');
    Route::get('/v1/schedule',        [ScheduleController::class, 'show'])->middleware('throttle:schedule');
    Route::delete('/v1/schedule',     [ScheduleController::class, 'destroy'])->middleware('throttle:schedule');
    Route::post('/v1/digest/deliver', [DigestController::class, 'deliver'])->middleware('throttle:digest');
    Route::post('/v1/compliance',     [ComplianceController::class, 'handle'])->middleware('throttle:compliance');
    Route::post('/v1/triage/push',       PushController::class)->middleware('throttle:triage');
    Route::post('/v1/triage/share',      ShareController::class)->middleware('throttle:triage');
    Route::get('/v1/triage/collisions',  CollisionsController::class)->middleware('throttle:triage');
});
