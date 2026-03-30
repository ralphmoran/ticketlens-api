<?php

use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SummarizeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.license')->group(function () {
    Route::post('/v1/summarize',        [SummarizeController::class, 'handle']);
    Route::post('/v1/schedule',         [ScheduleController::class, 'store']);
    Route::get('/v1/schedule',          [ScheduleController::class, 'show']);
    Route::delete('/v1/schedule',       [ScheduleController::class, 'destroy']);
    Route::post('/v1/digest/deliver',   [DigestController::class, 'deliver']);
});
