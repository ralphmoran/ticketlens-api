<?php

use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SummarizeController;
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

Route::middleware(['throttle:api-global', 'auth.license'])->group(function () {
    Route::post('/v1/summarize',      [SummarizeController::class, 'handle'])->middleware('throttle:summarize');
    Route::post('/v1/schedule',       [ScheduleController::class, 'store'])->middleware('throttle:schedule');
    Route::get('/v1/schedule',        [ScheduleController::class, 'show'])->middleware('throttle:schedule');
    Route::delete('/v1/schedule',     [ScheduleController::class, 'destroy'])->middleware('throttle:schedule');
    Route::post('/v1/digest/deliver', [DigestController::class, 'deliver'])->middleware('throttle:digest');
    Route::post('/v1/compliance',     [ComplianceController::class, 'handle'])->middleware('throttle:compliance');
});
