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
    private const ALLOWED_PERIODS = [7, 30, 90];

    public function index(Request $request): Response|RedirectResponse
    {
        $user   = $request->user();
        $period = in_array((int) $request->query('period'), self::ALLOWED_PERIODS)
            ? (int) $request->query('period')
            : 30;

        if ($user->is_owner) {
            return $this->ownerIndex($request, $period);
        }

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No team found.');

        return Inertia::render('Console/Admin/Stats', array_merge(
            $this->computeData($group, $period),
            ['owner_mode' => false, 'clients' => [], 'selected_manager' => null],
        ));
    }

    private function ownerIndex(Request $request, int $period): Response|RedirectResponse
    {
        $clients = User::whereHas('ownedGroup')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

        $managerId = (int) $request->query('manager_id', 0);

        if ($managerId <= 0) {
            return Inertia::render('Console/Admin/Stats', [
                'owner_mode'        => true,
                'clients'           => $clients,
                'selected_manager'  => null,
                'group_name'        => '',
                'daily_urgency'     => [],
                'team_comparison'   => [],
                'last_updated'      => null,
                'push_heatmap'      => [],
                'hour_distribution' => [],
                'day_of_week_dist'  => [],
                'engagement_scores' => [],
                'ticket_load_trend' => [],
                'workload_donut'    => ['labels' => [], 'data' => []],
            ]);
        }

        $manager = User::whereHas('ownedGroup')->find($managerId);

        if (! $manager) {
            return redirect('/console/admin/stats');
        }

        return Inertia::render('Console/Admin/Stats', array_merge(
            $this->computeData($manager->ownedGroup, $period),
            [
                'owner_mode'       => true,
                'clients'          => $clients,
                'selected_manager' => ['id' => $manager->id, 'name' => $manager->name, 'email' => $manager->email],
            ],
        ));
    }

    private function computeData(Group $group, int $period = 30): array
    {
        $members   = $group->members()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email']);
        $memberIds = $members->pluck('id');

        // Historical window: configurable period, cap at 1000 rows
        $historical = TriageSnapshot::whereIn('user_id', $memberIds)
            ->where('captured_at', '>=', now()->subDays($period))
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

        // 90-day window for activity stats (heatmap, hour, dow, engagement, ticket load).
        // tickets column intentionally excluded — large JSON blob, not needed for these metrics.
        $allSnapshots = TriageSnapshot::whereIn('user_id', $memberIds)
            ->where('captured_at', '>=', now()->subDays(90))
            ->orderByDesc('captured_at')
            ->limit(5000)
            ->get(['user_id', 'profile', 'ticket_count', 'captured_at'])
            ->groupBy('user_id');

        // Team comparison: latest snapshot per user+profile with ticket-flag data.
        // Separate query so tickets JSON is fetched only for the rows we actually need.
        $memberCount    = max(1, $memberIds->count());
        $latestByMember = TriageSnapshot::whereIn('user_id', $memberIds)
            ->where('captured_at', '>=', now()->subDays(90))
            ->orderByDesc('captured_at')
            ->limit($memberCount * 20)
            ->get(['user_id', 'profile', 'tickets', 'ticket_count', 'captured_at'])
            ->groupBy('user_id')
            ->map(fn ($snaps) => $snaps->unique(fn ($s) => $s->user_id . '|' . $s->profile));

        $teamComparison = $members->map(function ($member) use ($latestByMember) {
            $memberSnaps  = $latestByMember->get($member->id, collect());
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

        // Push heatmap: per member, unique push dates for the 90-day window
        $pushHeatmap = $members->map(function ($member) use ($allSnapshots) {
            $snaps = $allSnapshots->get($member->id, collect());
            $days  = $snaps
                ->map(fn ($s) => $s->captured_at->utc()->toDateString())
                ->unique()
                ->values()
                ->toArray();

            return [
                'member_id'   => $member->id,
                'member_name' => $member->name,
                'days'        => $days,
            ];
        })->values();

        // Hour-of-day distribution: 24 buckets (0–23), from captured_at hour (UTC)
        // Best-effort: daily-dedup means this reflects last push of each day per profile.
        $hourBuckets = array_fill(0, 24, 0);
        foreach ($allSnapshots->flatten() as $snap) {
            $hourBuckets[$snap->captured_at->utc()->hour]++;
        }
        $hourDistribution = array_map(
            fn ($h) => ['hour' => $h, 'count' => $hourBuckets[$h]],
            range(0, 23)
        );

        // Day-of-week distribution: 7 buckets (0=Sun … 6=Sat)
        $dowBuckets = array_fill(0, 7, 0);
        $dowLabels  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        foreach ($allSnapshots->flatten() as $snap) {
            $dowBuckets[$snap->captured_at->utc()->dayOfWeek]++;
        }
        $dayOfWeekDist = array_map(
            fn ($d) => ['day' => $dowLabels[$d], 'count' => $dowBuckets[$d]],
            range(0, 6)
        );

        // Engagement scores: active_days in last 30d, avg ticket_count, composite score
        $thirtyDaysAgo = now()->subDays(30);
        $engagementScores = $members->map(function ($member) use ($allSnapshots, $thirtyDaysAgo) {
            $snaps       = $allSnapshots->get($member->id, collect());
            $recentSnaps = $snaps->filter(fn ($s) => $s->captured_at->gte($thirtyDaysAgo));
            $activeDays  = $recentSnaps->map(fn ($s) => $s->captured_at->utc()->toDateString())->unique()->count();
            $avgTickets  = $recentSnaps->isNotEmpty() ? round($recentSnaps->avg('ticket_count'), 1) : 0.0;
            $score       = round(($activeDays / 30) * ($avgTickets > 0 ? log($avgTickets + 1) : 0), 2);

            return [
                'member_id'       => $member->id,
                'member_name'     => $member->name,
                'active_days_30d' => $activeDays,
                'avg_ticket_count'=> $avgTickets,
                'score'           => $score,
            ];
        })->sortByDesc('score')->values();

        // Ticket load trend: per member, daily ticket_count for last 30 days
        $ticketLoadTrend = $members->map(function ($member) use ($allSnapshots, $thirtyDaysAgo) {
            $snaps = $allSnapshots->get($member->id, collect())
                ->filter(fn ($s) => $s->captured_at->gte($thirtyDaysAgo))
                ->sortBy('captured_at');

            $data = $snaps->map(fn ($s) => [
                'date'  => $s->captured_at->utc()->toDateString(),
                'count' => $s->ticket_count,
            ])->values()->toArray();

            return [
                'member_id'   => $member->id,
                'member_name' => $member->name,
                'data'        => $data,
            ];
        })->filter(fn ($row) => count($row['data']) > 0)->values();

        // Workload donut: current flag distribution across all team members (latest snapshot per member)
        $currentTickets = collect($latestByMember->flatten())
            ->flatMap(fn ($s) => $s->tickets ?? []);

        $workloadDonut = [
            'labels' => ['Needs Response', 'Aging', 'Stale', 'Clear'],
            'data'   => [
                $currentTickets->filter(fn ($t) => in_array('needs-response', $t['flags'] ?? []))->count(),
                $currentTickets->filter(fn ($t) => in_array('aging', $t['flags'] ?? []))->count(),
                $currentTickets->filter(fn ($t) => in_array('stale', $t['flags'] ?? []))->count(),
                $currentTickets->filter(fn ($t) => empty($t['flags']))->count(),
            ],
        ];

        return [
            'group_name'        => $group->name,
            'daily_urgency'     => $dailyUrgency,
            'team_comparison'   => $teamComparison,
            'last_updated'      => $historical->max('captured_at')?->toIso8601String(),
            'push_heatmap'      => $pushHeatmap,
            'hour_distribution' => $hourDistribution,
            'day_of_week_dist'  => $dayOfWeekDist,
            'engagement_scores' => $engagementScores,
            'ticket_load_trend' => $ticketLoadTrend,
            'workload_donut'    => $workloadDonut,
        ];
    }
}
