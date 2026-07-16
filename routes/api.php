<?php

use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\Recall\PullController as RecallPullController;
use App\Http\Controllers\Api\Recall\PushController as RecallPushController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SummarizeController;
use App\Http\Controllers\Api\TeamJiraConfigController;
use App\Http\Controllers\Api\Triage\CollisionsController;
use App\Http\Controllers\Api\Triage\PushController;
use App\Http\Controllers\Api\Triage\ShareController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Public health check — no auth, rate-limited by IP
RateLimiter::for('health', fn(Request $r) => Limit::perMinute(60)->by($r->ip()));
Route::get('/v1/health', \App\Http\Controllers\Api\HealthController::class)
    ->middleware('throttle:health')
    ->name('api.health');

// Define named rate limiters
RateLimiter::for('api-global', fn(Request $r) => Limit::perMinute(120)->by($r->ip()));
RateLimiter::for('summarize',  fn(Request $r) => Limit::perMinute(10)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('schedule',   fn(Request $r) => Limit::perMinute(5)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('digest',      fn(Request $r) => Limit::perMinute(20)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('compliance',  fn(Request $r) => Limit::perMinute(10)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('ai-test',     fn(Request $r) => Limit::perMinute(5)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('triage',      fn(Request $r) => Limit::perMinute(30)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('recall',      fn(Request $r) => Limit::perMinute(30)->by($r->bearerToken() ?: $r->ip()));
RateLimiter::for('team-config', fn(Request $r) => Limit::perMinute(30)->by($r->bearerToken() ?: $r->ip()));

// Public license activation/validation — no auth, rate-limited by IP
RateLimiter::for('license-act', fn(Request $r) => Limit::perMinute(10)->by($r->ip()));
Route::middleware(['throttle:api-global', 'throttle:license-act'])->group(function () {
    Route::post('/v1/licenses/activate', [\App\Http\Controllers\Api\LicenseActivationController::class, 'activate']);
    Route::post('/v1/licenses/validate', [\App\Http\Controllers\Api\LicenseActivationController::class, 'validate']);
});

// CLI token — auth via CLI token (profiles, triage push/share/collisions, schedule)
RateLimiter::for('profiles', fn(Request $r) => Limit::perMinute(30)->by($r->bearerToken() ?: $r->ip()));
Route::middleware(['throttle:api-global', 'auth.cli'])->group(function () {
    Route::get('/v1/profiles', \App\Http\Controllers\Api\ProfileSyncController::class)
        ->middleware('throttle:profiles')
        ->name('api.profiles');

    Route::get('/v1/statuses', \App\Http\Controllers\Api\StatusCacheController::class)
        ->middleware('throttle:profiles')
        ->name('api.statuses');

    Route::get('/v1/templates', \App\Http\Controllers\Api\BriefTemplateController::class)
        ->middleware('throttle:profiles')
        ->name('api.templates');

    Route::post('/v1/triage/push',      PushController::class)->middleware('throttle:triage');
    Route::post('/v1/triage/share',     ShareController::class)->middleware('throttle:triage');
    Route::get('/v1/triage/collisions', CollisionsController::class)->middleware('throttle:triage');

    Route::post('/v1/recall/push', RecallPushController::class)->middleware('throttle:recall');
    Route::get('/v1/recall/pull',  RecallPullController::class)->middleware('throttle:recall');

    Route::post('/v1/schedule',   [ScheduleController::class, 'store'])->middleware('throttle:schedule');
    Route::get('/v1/schedule',    [ScheduleController::class, 'show'])->middleware('throttle:schedule');
    Route::delete('/v1/schedule', [ScheduleController::class, 'destroy'])->middleware('throttle:schedule');
});

// Digest delivery: Pro tier, license-key auth (called from digest job, not CLI session)
Route::middleware(['throttle:api-global', 'auth.license', 'license.tier:pro'])->group(function () {
    Route::post('/v1/digest/deliver', [DigestController::class, 'deliver'])->middleware('throttle:digest');
});

// CLI features: CLI token auth — user tier checked directly (same user who manages their BYOK keys)
Route::middleware(['throttle:api-global', 'auth.cli'])->group(function () {
    Route::post('/v1/summarize',  [SummarizeController::class, 'handle'])->middleware(['throttle:summarize', 'license.tier:pro']);
    Route::post('/v1/compliance', [ComplianceController::class, 'handle'])->middleware(['throttle:compliance', 'license.tier:team']);
});

// Team Jira config: Pro+ CLI users — non-secret Jira config shared across the team
Route::middleware(['throttle:api-global', 'auth.cli', 'license.tier:pro'])->group(function () {
    Route::get('/v1/team/config', [TeamJiraConfigController::class, 'show'])
        ->middleware('throttle:team-config')
        ->name('api.team.config');
});

// AI provider management: CLI users with a CliToken (sets $request->user() via auth.cli)
Route::middleware(['throttle:api-global', 'auth.cli'])->group(function () {
    Route::get('/v1/ai-providers',              [AiProviderController::class, 'index']);
    Route::post('/v1/ai-providers',             [AiProviderController::class, 'store']);
    Route::put('/v1/ai-providers/{id}',         [AiProviderController::class, 'update']);
    Route::delete('/v1/ai-providers/{id}',      [AiProviderController::class, 'destroy']);
    Route::post('/v1/ai-providers/{id}/test',   [AiProviderController::class, 'test'])->middleware('throttle:ai-test');
});
