<?php

namespace App\Http\Controllers\Console;

use App\Models\DigestSchedule;
use App\Models\License;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchedulesController
{
    public function index(Request $request): Response
    {
        $user    = $request->user();
        $license = License::where('user_id', $user->id)->first();

        if (! $license) {
            return Inertia::render('Console/Schedules', [
                'schedules'  => [],
                'hasLicense' => false,
                'timezones'  => \DateTimeZone::listIdentifiers(),
            ]);
        }

        $schedules = DigestSchedule::where('license_key_hash', $license->lemon_key_hash)
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'timezone', 'deliver_at', 'active', 'last_delivered_at', 'created_at']);

        return Inertia::render('Console/Schedules', [
            'schedules'  => $schedules,
            'hasLicense' => true,
            'timezones'  => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
