<?php

namespace App\Http\Controllers\Console;

use App\Models\Group;
use App\Models\TriageSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            'insights'      => $this->individualInsights($user),
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
            'insights'               => $this->managerInsights($user, $group),
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

    private function individualInsights(mixed $user): array
    {
        $window  = config('tiers.windows')[$user->tier] ?? 30;
        $cutoff  = now()->subDays($window);
        $isPro   = in_array($user->tier, ['pro', 'team']);

        $logs = DB::table('usage_logs')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $cutoff)
            ->whereNotNull('metadata')
            ->get(['action', 'tokens_used', 'metadata', 'created_at']);

        $commandsRun = $this->sumCommandCount($logs);
        $activeDays  = $logs->map(fn ($r) => substr($r->created_at, 0, 10))->unique()->count();
        $commandMix  = $this->commandMix($logs);

        $insights = [
            'commands_run' => $commandsRun,
            'active_days'  => $activeDays,
            'command_mix'  => $commandMix,
            'tokens_saved' => null,
            'window_days'  => $window,
        ];

        if ($isPro) {
            $tokensSaved                = (int) $logs->sum('tokens_used');
            $rate                       = config('tiers.token_rate_per_million');
            $insights['tokens_saved']   = $tokensSaved;
            $insights['estimated_savings'] = round($tokensSaved / 1_000_000 * $rate, 4);
        }

        return $insights;
    }

    private function managerInsights(mixed $user, Group $group): array
    {
        $self        = $this->individualInsights($user);
        $memberIds   = $group->members()->pluck('users.id')->toArray();
        $window      = config('tiers.windows')[$user->tier] ?? 90;
        $cutoff      = now()->subDays($window);
        $weekCutoff  = now()->subDays(7);

        if (empty($memberIds)) {
            $self['team'] = [
                'tokens_saved'    => 0,
                'active_this_week'=> 0,
                'adoption_rate'   => 0,
                'command_mix'     => [],
            ];
            return $self;
        }

        $teamLogs = DB::table('usage_logs')
            ->whereIn('user_id', $memberIds)
            ->where('created_at', '>=', $cutoff)
            ->whereNotNull('metadata')
            ->get(['user_id', 'action', 'tokens_used', 'metadata', 'created_at']);

        $activeThisWeek = DB::table('usage_logs')
            ->whereIn('user_id', $memberIds)
            ->where('created_at', '>=', $weekCutoff)
            ->whereNotNull('metadata')
            ->distinct()
            ->count('user_id');

        $teamCommandMix = $this->commandMix($teamLogs);

        $self['team'] = [
            'tokens_saved'     => (int) $teamLogs->sum('tokens_used'),
            'active_this_week' => $activeThisWeek,
            'adoption_rate'    => count($memberIds) > 0
                ? round($activeThisWeek / count($memberIds), 4)
                : 0,
            'command_mix'      => $teamCommandMix,
        ];

        return $self;
    }

    /**
     * Sum the per-row command counts stored in each usage log's JSON metadata.
     */
    private function sumCommandCount(Collection $logs): int
    {
        return (int) $logs->sum(fn ($r) => json_decode($r->metadata, true)['count'] ?? 0);
    }

    /**
     * Total command runs per action, keyed by action name.
     */
    private function commandMix(Collection $logs): array
    {
        return $logs->groupBy('action')
            ->map(fn ($rows) => $this->sumCommandCount($rows))
            ->toArray();
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
