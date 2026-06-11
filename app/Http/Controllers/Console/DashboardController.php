<?php

namespace App\Http\Controllers\Console;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    private const ALLOWED_PERIODS = [7, 30, 90];

    public function index(Request $request): Response
    {
        $user   = $request->user();
        $period = in_array((int) $request->query('period'), self::ALLOWED_PERIODS)
            ? (int) $request->query('period')
            : 30;

        // ── Owner: platform-wide aggregate ───────────────────────────────────
        if ($user->is_owner) {
            return $this->ownerDashboard($user, $period);
        }

        // ── Team manager: group aggregate ─────────────────────────────────────
        $ownedGroup = $user->tier === 'team' ? $user->ownedGroup : null;
        if ($ownedGroup !== null) {
            return $this->teamManagerDashboard($user, $ownedGroup, $period);
        }

        // ── Free / Pro / team member: individual stats ────────────────────────
        return $this->individualDashboard($user, $period);
    }

    private function individualDashboard(mixed $user, int $period): Response
    {
        $snapshots = TriageSnapshot::where('user_id', $user->id)
            ->where('captured_at', '>=', now()->subDays(90))
            ->orderBy('captured_at')
            ->get(['ticket_count', 'captured_at']);

        $monthStart  = now()->startOfMonth();
        $pushesMonth = $snapshots->filter(fn ($s) => $s->captured_at->gte($monthStart))->count();
        $latestSnap  = $snapshots->last();
        $currentLoad = $latestSnap?->ticket_count ?? 0;
        $lastPush    = $latestSnap?->captured_at?->toIso8601String();
        $pushStreak  = $this->computeStreak($snapshots);

        $stats = [
            'pushes_this_month'    => $pushesMonth,
            'current_ticket_count' => $currentLoad,
            'push_streak'          => $pushStreak,
            'last_push'            => $lastPush,
        ];

        $ticketTrend   = [];
        $dailyUrgency  = [];
        $hourDist      = null;
        $dayOfWeekDist = null;

        if (in_array($user->tier, ['pro', 'team'])) {
            $windowStart = now()->subDays($period);

            $ticketTrend = $snapshots
                ->filter(fn ($s) => $s->captured_at->gte($windowStart))
                ->map(fn ($s) => [
                    'date'  => $s->captured_at->toDateString(),
                    'count' => $s->ticket_count,
                ])
                ->values()
                ->toArray();

            $urgencySnaps = TriageSnapshot::where('user_id', $user->id)
                ->where('captured_at', '>=', $windowStart)
                ->orderBy('captured_at')
                ->limit(1000)
                ->get(['profile', 'tickets', 'captured_at']);

            $dailyUrgency = $urgencySnaps
                ->groupBy(fn ($s) => $s->captured_at->toDateString())
                ->map(function ($daySnaps, $date) {
                    $latest  = $daySnaps->sortByDesc('captured_at')->unique('profile');
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
                ->values()
                ->toArray();

            $hourBuckets = array_fill(0, 24, 0);
            $dowBuckets  = array_fill(0, 7, 0);
            foreach ($snapshots as $s) {
                $hourBuckets[$s->captured_at->hour]++;
                $dowBuckets[$s->captured_at->dayOfWeek]++;
            }
            $hourDist      = $hourBuckets;
            $dayOfWeekDist = $dowBuckets;
        }

        $payload = [
            'stats'         => $stats,
            'ticket_trend'  => $ticketTrend,
            'daily_urgency' => $dailyUrgency,
        ];

        if ($hourDist !== null) {
            $payload['hour_distribution'] = $hourDist;
            $payload['day_of_week_dist']  = $dayOfWeekDist;
        }

        return Inertia::render('Console/Dashboard', $payload);
    }

    private function teamManagerDashboard(mixed $user, Group $group, int $period): Response
    {
        $memberIds = $group->members()->pluck('users.id')->toArray();

        // Individual stats for the manager themselves (push streak, last push)
        $selfSnaps = TriageSnapshot::where('user_id', $user->id)
            ->where('captured_at', '>=', now()->subDays(90))
            ->orderBy('captured_at')
            ->get(['ticket_count', 'captured_at']);

        $latestSelf = $selfSnaps->last();
        $stats = [
            'pushes_this_month'    => $selfSnaps->filter(fn ($s) => $s->captured_at->gte(now()->startOfMonth()))->count(),
            'current_ticket_count' => $latestSelf?->ticket_count ?? 0,
            'push_streak'          => $this->computeStreak($selfSnaps),
            'last_push'            => $latestSelf?->captured_at?->toIso8601String(),
        ];

        // Team snapshots — all members, last 90 days
        $teamSnaps = !empty($memberIds)
            ? TriageSnapshot::whereIn('user_id', $memberIds)
                ->where('captured_at', '>=', now()->subDays(90))
                ->orderBy('captured_at')
                ->get(['user_id', 'ticket_count', 'tickets', 'captured_at'])
            : collect();

        // Latest snapshot per member (for KPIs)
        $latestPerMember = $teamSnaps->groupBy('user_id')
            ->map(fn ($snaps) => $snaps->sortByDesc('captured_at')->first());

        $activeMembersToday = $teamSnaps
            ->filter(fn ($s) => $s->captured_at->gte(now()->subDay()))
            ->pluck('user_id')
            ->unique()
            ->count();

        $teamPushesWeek = $teamSnaps
            ->filter(fn ($s) => $s->captured_at->gte(now()->subWeek()))
            ->count();

        $needsResponseTotal = $latestPerMember->sum(function ($snap) {
            return collect($snap->tickets ?? [])
                ->filter(fn ($t) => in_array('needs-response', $t['flags'] ?? []))
                ->count();
        });

        $avgTicketLoad = $latestPerMember->isNotEmpty()
            ? round($latestPerMember->avg('ticket_count'), 1)
            : 0;

        $kpiStats = [
            ['label' => 'Active Today',      'value' => $activeMembersToday, 'hint' => 'members pushed in last 24h'],
            ['label' => 'Team Pushes / Wk',  'value' => $teamPushesWeek,     'hint' => 'total pushes this week'],
            ['label' => 'Needs Response',     'value' => $needsResponseTotal, 'hint' => 'tickets flagged across team'],
            ['label' => 'Avg Ticket Load',    'value' => $avgTicketLoad,      'hint' => 'avg open tickets per member'],
        ];

        // Hour + DOW distributions (team aggregate)
        $hourBuckets = array_fill(0, 24, 0);
        $dowBuckets  = array_fill(0, 7, 0);
        foreach ($teamSnaps as $s) {
            $hourBuckets[$s->captured_at->hour]++;
            $dowBuckets[$s->captured_at->dayOfWeek]++;
        }

        // Per-member push heatmap: [{ member_id, days: ['2026-05-01', ...] }, ...]
        $heatmap = $latestPerMember->keys()->map(function ($memberId) use ($teamSnaps) {
            $days = $teamSnaps
                ->filter(fn ($s) => $s->user_id === $memberId)
                ->map(fn ($s) => $s->captured_at->toDateString())
                ->unique()
                ->values()
                ->toArray();

            return ['member_id' => $memberId, 'days' => $days];
        })->values()->toArray();

        // Individual Pro-level charts for the manager's own data
        $windowStart  = now()->subDays($period);
        $ticketTrend  = $selfSnaps
            ->filter(fn ($s) => $s->captured_at->gte($windowStart))
            ->map(fn ($s) => ['date' => $s->captured_at->toDateString(), 'count' => $s->ticket_count])
            ->values()
            ->toArray();

        $urgencySnaps = TriageSnapshot::where('user_id', $user->id)
            ->where('captured_at', '>=', $windowStart)
            ->orderBy('captured_at')
            ->limit(1000)
            ->get(['profile', 'tickets', 'captured_at']);

        $dailyUrgency = $urgencySnaps
            ->groupBy(fn ($s) => $s->captured_at->toDateString())
            ->map(function ($daySnaps, $date) {
                $latest  = $daySnaps->sortByDesc('captured_at')->unique('profile');
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
            ->values()
            ->toArray();

        return Inertia::render('Console/Dashboard', [
            'stats'                  => $stats,
            'ticket_trend'           => $ticketTrend,
            'daily_urgency'          => $dailyUrgency,
            'hour_distribution'      => array_values(
                array_fill(0, 24, 0)  // individual — empty (team charts take over)
            ),
            'day_of_week_dist'       => array_values(array_fill(0, 7, 0)),
            'kpi_stats'              => $kpiStats,
            'team_hour_distribution' => array_values($hourBuckets),
            'team_dow_distribution'  => array_values($dowBuckets),
            'team_push_heatmap'      => $heatmap,
        ]);
    }

    private function ownerDashboard(mixed $user, int $period): Response
    {
        // Platform-wide snapshots — last 90 days
        $allSnaps = TriageSnapshot::where('captured_at', '>=', now()->subDays(90))
            ->get(['user_id', 'ticket_count', 'captured_at']);

        $dau = $allSnaps->filter(fn ($s) => $s->captured_at->gte(now()->subDay()))->pluck('user_id')->unique()->count();
        $wau = $allSnaps->filter(fn ($s) => $s->captured_at->gte(now()->subWeek()))->pluck('user_id')->unique()->count();
        $mau = $allSnaps->filter(fn ($s) => $s->captured_at->gte(now()->subDays(30)))->pluck('user_id')->unique()->count();

        $activeUserIdsToday = TriageSnapshot::where('captured_at', '>=', now()->subDay())
            ->pluck('user_id')
            ->unique();

        $activeTeamsToday = Group::whereHas('members', function ($q) use ($activeUserIdsToday) {
            $q->whereIn('users.id', $activeUserIdsToday);
        })->count();

        $kpiStats = [
            ['label' => 'DAU',              'value' => $dau,              'hint' => 'unique active users today'],
            ['label' => 'WAU',              'value' => $wau,              'hint' => 'unique active users this week'],
            ['label' => 'Active Teams',     'value' => $activeTeamsToday, 'hint' => 'teams with pushes in last 24h'],
            ['label' => 'MAU',              'value' => $mau,              'hint' => 'unique active users this month'],
        ];

        // Platform hour + DOW distributions
        $hourBuckets = array_fill(0, 24, 0);
        $dowBuckets  = array_fill(0, 7, 0);
        foreach ($allSnaps as $s) {
            $hourBuckets[$s->captured_at->hour]++;
            $dowBuckets[$s->captured_at->dayOfWeek]++;
        }

        // Owner self-stats (for the stats card strip)
        $selfSnaps  = $allSnaps->where('user_id', $user->id);
        $latestSelf = $selfSnaps->sortByDesc('captured_at')->first();
        $stats = [
            'pushes_this_month'    => $selfSnaps->filter(fn ($s) => $s->captured_at->gte(now()->startOfMonth()))->count(),
            'current_ticket_count' => $latestSelf?->ticket_count ?? 0,
            'push_streak'          => 0,
            'last_push'            => $latestSelf?->captured_at?->toIso8601String(),
        ];

        return Inertia::render('Console/Dashboard', [
            'stats'                  => $stats,
            'ticket_trend'           => [],
            'daily_urgency'          => [],
            'kpi_stats'              => $kpiStats,
            'team_hour_distribution' => array_values($hourBuckets),
            'team_dow_distribution'  => array_values($dowBuckets),
            'team_push_heatmap'      => [],
        ]);
    }

    private function computeStreak($snapshots): int
    {
        if ($snapshots->isEmpty()) {
            return 0;
        }

        $dates = $snapshots
            ->map(fn ($s) => $s->captured_at->utc()->toDateString())
            ->unique()
            ->sortDesc()
            ->values();

        $today     = Carbon::now()->utc()->toDateString();
        $yesterday = Carbon::now()->utc()->subDay()->toDateString();

        if ($dates->first() !== $today && $dates->first() !== $yesterday) {
            return 0;
        }

        $streak   = 1;
        $expected = Carbon::parse($dates->first())->subDay()->toDateString();

        foreach ($dates->slice(1) as $date) {
            if ($date === $expected) {
                $streak++;
                $expected = Carbon::parse($date)->subDay()->toDateString();
            } else {
                break;
            }
        }

        return $streak;
    }
}
