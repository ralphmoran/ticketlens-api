<?php

use App\Jobs\RevokeExpiredGrantsJob;
use App\Jobs\SendSlackDigestJob;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(RevokeExpiredGrantsJob::class)->hourly();

// Dispatch once per minute — the job self-selects due schedules by timezone-aware day+time matching.
Schedule::call(fn () => SendSlackDigestJob::dispatch(Carbon::now()))->everyMinute();
