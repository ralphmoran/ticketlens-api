<?php

namespace App\Http\Controllers\Console\Admin;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatsController
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->is_owner) {
            return $this->ownerIndex($request);
        }

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');

        return Inertia::render('Console/Admin/Stats', array_merge(
            $this->computeData($group),
            ['owner_mode' => false, 'clients' => [], 'selected_manager' => null],
        ));
    }

    private function ownerIndex(Request $request): Response|RedirectResponse
    {
        $clients = User::whereHas('ownedGroup')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

        $managerId = (int) $request->query('manager_id', 0);

        if ($managerId <= 0) {
            return Inertia::render('Console/Admin/Stats', [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => null,
                'group_name'       => '',
                'daily_urgency'    => [],
                'team_comparison'  => [],
                'last_updated'     => null,
            ]);
        }

        $manager = User::whereHas('ownedGroup')->find($managerId);

        if (! $manager) {
            return redirect('/console/admin/stats');
        }

        return Inertia::render('Console/Admin/Stats', array_merge(
            $this->computeData($manager->ownedGroup),
            [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => ['id' => $manager->id, 'name' => $manager->name, 'email' => $manager->email],
            ],
        ));
    }

    private function computeData(Group $group): array
    {
        $members   = $group->members()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']);
        $memberIds = $members->pluck('id');

        // Historical window: last 30 days, cap at 1000 rows
        $historical = TriageSnapshot::whereIn('user_id', $memberIds)
            ->where('captured_at', '>=', now()->subDays(30))
            ->orderBy('captured_at')
            ->limit(1000)
            ->get(['user_id', 'profile', 'tickets', 'captured_at']);

        // Daily urgency trend: one point per calendar day (latest snapshot per user+profile+day)
        $dailyUrgency = $historical
            ->groupBy(fn ($s) => Carbon::parse($s->captured_at)->toDateString())
            ->map(function ($daySnaps, $date) {
                // Within a day, keep only the latest per user+profile (sortByDesc ensures newest is first)
                $latest = $daySnaps->sortByDesc('captured_at')->unique(fn ($s) => $s->user_id . '|' . $s->profile);
                $tickets = $latest->flatMap(fn ($s) => $s->tickets ?? []);

                return [
                    'date'           => $date,
                    'needs_response' => $tickets->filter(fn ($t) => in_array('needs-response', $t['flags'] ?? []))->count(),
                    'aging'          => $tickets->filter(fn ($t) => in_array('aging', $t['flags'] ?? []))->count(),
                    'stale'          => $tickets->filter(fn ($t) => in_array('stale', $t['flags'] ?? []))->count(),
                    'clear'          => $tickets->filter(fn ($t) => empty($t['flags']))->count(),
                ];
            })
            ->sortKeys()
            ->values();

        // Team comparison: latest snapshot per user+profile, aggregated per member
        $allSnapshots = TriageSnapshot::whereIn('user_id', $memberIds)
            ->where('captured_at', '>=', now()->subDays(90))
            ->orderByDesc('captured_at')
            ->get(['user_id', 'profile', 'tickets', 'ticket_count', 'captured_at'])
            ->unique(fn ($s) => $s->user_id . '|' . $s->profile)
            ->groupBy('user_id');

        $teamComparison = $members->map(function ($member) use ($allSnapshots) {
            $memberSnaps  = $allSnapshots->get($member->id, collect());
            $tickets      = $memberSnaps->flatMap(fn ($s) => $s->tickets ?? []);
            $needsResp    = $tickets->filter(fn ($t) => in_array('needs-response', $t['flags'] ?? []))->count();
            $aging        = $tickets->filter(fn ($t) => in_array('aging', $t['flags'] ?? []))->count();
            $stale        = $tickets->filter(fn ($t) => in_array('stale', $t['flags'] ?? []))->count();
            $clear        = $tickets->filter(fn ($t) => empty($t['flags']))->count();
            $lastPush     = $memberSnaps->max('captured_at');

            return [
                'member_id'      => $member->id,
                'member_name'    => $member->name,
                'needs_response' => $needsResp,
                'aging'          => $aging,
                'stale'          => $stale,
                'clear'          => $clear,
                'total'          => $tickets->count(),
                'last_push'      => $lastPush?->toIso8601String(),
            ];
        })->values();

        return [
            'group_name'      => $group->name,
            'daily_urgency'   => $dailyUrgency,
            'team_comparison' => $teamComparison,
            'last_updated'    => $historical->max('captured_at')?->toIso8601String(),
        ];
    }
}
