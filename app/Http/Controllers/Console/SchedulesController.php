<?php

namespace App\Http\Controllers\Console;

use App\Http\Requests\ScheduleRequest;
use App\Models\DigestSchedule;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchedulesController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->is_owner) {
            $ownerHash      = $this->ownerHash($user);
            $scheduleSearch = $request->string('scheduleSearch')->trim()->value();

            // Client picker (form) — always resolved so the assign-to-client search works
            // regardless of whether scheduleSearch is present.
            $clientQuery = $request->string('clientSearch')->trim()->value();
            $clients = $clientQuery
                ? User::where('is_owner', false)
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('name', 'like', "%{$clientQuery}%")
                                        ->orWhere('email', 'like', "%{$clientQuery}%"))
                    ->orderBy('name')
                    ->limit(15)
                    ->get(['id', 'name', 'email'])
                : collect();

            $emptyPaginator = ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1, 'prev_page_url' => null, 'next_page_url' => null];

            if (! $scheduleSearch) {
                return Inertia::render('Console/Schedules', [
                    'schedules'  => $emptyPaginator,
                    'hasLicense' => true,
                    'timezones'  => \DateTimeZone::listIdentifiers(),
                    'clients'    => $clients,
                ]);
            }

            $schedules = DigestSchedule::where(function ($q) use ($ownerHash) {
                    $q->where('license_key_hash', $ownerHash)
                      ->orWhereNotNull('assigned_to_user_id');
                })
                ->where(function ($q) use ($scheduleSearch) {
                    $q->where('email', 'like', "%{$scheduleSearch}%")
                      ->orWhereHas('assignedTo', fn ($r) => $r
                          ->where('name', 'like', "%{$scheduleSearch}%")
                          ->orWhere('email', 'like', "%{$scheduleSearch}%")
                      );
                })
                ->select($this->scheduleColumns())
                ->with('assignedTo:id,name,email')
                ->orderByDesc('created_at')
                ->paginate(15)
                ->through(fn ($s) => $this->appendNextDelivery($s));

            return Inertia::render('Console/Schedules', [
                'schedules'  => $schedules,
                'hasLicense' => true,
                'timezones'  => \DateTimeZone::listIdentifiers(),
                'clients'    => $clients,
            ]);
        }

        $license = License::where('user_id', $user->id)->first();

        $emptyPaginator = ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1, 'prev_page_url' => null, 'next_page_url' => null];

        if (! $license) {
            return Inertia::render('Console/Schedules', [
                'schedules'  => $emptyPaginator,
                'hasLicense' => false,
                'timezones'  => \DateTimeZone::listIdentifiers(),
                'clients'    => [],
            ]);
        }

        $schedules = DigestSchedule::where(function ($q) use ($user, $license) {
                $q->where('license_key_hash', $license->lemon_key_hash)
                  ->orWhere('user_id', $user->id);
            })
            ->select($this->scheduleColumns())
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn ($s) => $this->appendNextDelivery($s));

        return Inertia::render('Console/Schedules', [
            'schedules'  => $schedules,
            'hasLicense' => true,
            'timezones'  => \DateTimeZone::listIdentifiers(),
            'clients'    => [],
        ]);
    }

    public function store(ScheduleRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($user->is_owner) {
            $clientUserId = $request->integer('clientUserId') ?: null;
            $hash = $clientUserId
                ? DigestSchedule::hashKey('assigned:' . $clientUserId)
                : $this->ownerHash($user);

            DigestSchedule::create([
                'license_key_hash'    => $hash,
                'assigned_to_user_id' => $clientUserId,
                'email'               => $data['email'],
                'timezone'            => $data['timezone'],
                'deliver_at'          => $data['deliverAt'],
                'active'              => true,
            ]);

            return redirect()->route('console.schedules');
        }

        $license = License::where('user_id', $user->id)->first();

        if (! $license) {
            return back()->withErrors(['license' => 'No active license found. Upgrade to Pro to manage schedules.']);
        }

        DigestSchedule::create([
            'license_key_hash' => $license->lemon_key_hash,
            'email'            => $data['email'],
            'timezone'         => $data['timezone'],
            'deliver_at'       => $data['deliverAt'],
            'active'           => true,
        ]);

        return redirect()->route('console.schedules');
    }

    public function update(Request $request, DigestSchedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($schedule, $request->user());

        $data = $request->validate([
            'email'     => ['required', 'email:rfc'],
            'timezone'  => ['required', 'string', \Illuminate\Validation\Rule::in(\DateTimeZone::listIdentifiers())],
            'deliverAt' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $schedule->update([
            'email'      => $data['email'],
            'timezone'   => $data['timezone'],
            'deliver_at' => $data['deliverAt'],
        ]);

        return redirect()->route('console.schedules');
    }

    public function toggle(Request $request, DigestSchedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($schedule, $request->user());

        $schedule->update(['active' => ! $schedule->active]);

        return redirect()->route('console.schedules');
    }

    public function destroy(Request $request, DigestSchedule $schedule): RedirectResponse
    {
        $this->authorizeSchedule($schedule, $request->user());

        $schedule->delete();

        return redirect()->route('console.schedules');
    }

    // -------------------------------------------------------------------------

    private function authorizeSchedule(DigestSchedule $schedule, User $user): void
    {
        if ($user->is_owner) {
            return;
        }

        abort_if(
            $schedule->license_key_hash !== $this->hashForUser($user) && $schedule->user_id !== $user->id,
            403
        );
    }

    private function hashForUser(User $user): ?string
    {
        if ($user->is_owner) {
            return $this->ownerHash($user);
        }

        $license = License::where('user_id', $user->id)->first();

        return $license?->lemon_key_hash;
    }

    private function ownerHash(User $user): string
    {
        return DigestSchedule::hashKey('owner:' . $user->id);
    }

    private function scheduleColumns(): array
    {
        return ['id', 'license_key_hash', 'user_id', 'assigned_to_user_id', 'email', 'timezone',
                'deliver_at', 'active', 'last_delivered_at', 'created_at'];
    }

    private function appendNextDelivery(DigestSchedule $schedule): DigestSchedule
    {
        $schedule->next_delivery_at = $schedule->nextDeliveryAt()->toIso8601String();
        return $schedule;
    }
}
