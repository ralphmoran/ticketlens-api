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
        $hash = $this->hashForUser($request->user());

        if (! $hash) {
            return back()->withErrors(['license' => 'No active license found. Upgrade to Pro to manage schedules.']);
        }

        $data = $request->validated();

        DigestSchedule::updateOrCreate(
            ['license_key_hash' => $hash],
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
        $hash = $this->hashForUser($user);

        $schedules = $hash
            ? DigestSchedule::where('license_key_hash', $hash)
                ->orderByDesc('created_at')
                ->get(['id', 'email', 'timezone', 'deliver_at', 'active', 'last_delivered_at', 'created_at'])
            : collect();

        return Inertia::render('Console/Schedules', [
            'schedules'  => $schedules,
            'hasLicense' => $user->is_owner || $hash !== null,
            'timezones'  => \DateTimeZone::listIdentifiers(),
        ]);
    }

    private function hashForUser(\App\Models\User $user): ?string
    {
        if ($user->is_owner) {
            return DigestSchedule::hashKey('owner:' . $user->id);
        }

        $license = License::where('user_id', $user->id)->first();

        return $license?->lemon_key_hash;
    }
}
