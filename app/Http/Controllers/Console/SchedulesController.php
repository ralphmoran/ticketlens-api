<?php

namespace App\Http\Controllers\Console;

use App\Http\Requests\ScheduleRequest;
use App\Models\DigestSchedule;
use App\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchedulesController
{
    public function store(ScheduleRequest $request): RedirectResponse
    {
        $license = License::where('user_id', $request->user()->id)->first();

        if (! $license) {
            return back()->withErrors(['license' => 'No active license found. Upgrade to Pro to manage schedules.']);
        }

        $data = $request->validated();

        DigestSchedule::updateOrCreate(
            ['license_key_hash' => $license->lemon_key_hash],
            [
                'email'      => $data['email'],
                'timezone'   => $data['timezone'],
                'deliver_at' => $data['deliverAt'],
                'active'     => true,
            ]
        );

        return redirect()->route('console.schedules');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->is_owner) {
            return Inertia::render('Console/Schedules', [
                'schedules'  => [],
                'hasLicense' => true,
                'timezones'  => \DateTimeZone::listIdentifiers(),
            ]);
        }

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
