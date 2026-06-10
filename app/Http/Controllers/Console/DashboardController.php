<?php

namespace App\Http\Controllers\Console;

use App\Models\TriageSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $snapshots = TriageSnapshot::where('user_id', $user->id)
            ->where('captured_at', '>=', now()->subDays(90))
            ->orderBy('captured_at')
            ->get(['ticket_count', 'captured_at']);

        $monthStart   = now()->startOfMonth();
        $pushesMonth  = $snapshots->filter(fn ($s) => $s->captured_at->gte($monthStart))->count();
        $latestSnap   = $snapshots->last();
        $currentLoad  = $latestSnap?->ticket_count ?? 0;
        $lastPush     = $latestSnap?->captured_at?->toIso8601String();
        $pushStreak   = $this->computeStreak($snapshots);

        $stats = [
            'pushes_this_month'    => $pushesMonth,
            'current_ticket_count' => $currentLoad,
            'push_streak'          => $pushStreak,
            'last_push'            => $lastPush,
        ];

        // Ticket trend (30d) — Pro, Team, and owner only
        $ticketTrend = [];
        if (in_array($user->tier, ['pro', 'team', 'owner'])) {
            $ticketTrend = $snapshots
                ->filter(fn ($s) => $s->captured_at->gte(now()->subDays(30)))
                ->map(fn ($s) => [
                    'date'  => $s->captured_at->toDateString(),
                    'count' => $s->ticket_count,
                ])
                ->values()
                ->toArray();
        }

        // Personal urgency trend (30d) — Pro, Team, and owner only
        $dailyUrgency = [];
        if (in_array($user->tier, ['pro', 'team', 'owner'])) {
            $urgencySnaps = TriageSnapshot::where('user_id', $user->id)
                ->where('captured_at', '>=', now()->subDays(30))
                ->orderBy('captured_at')
                ->limit(1000)
                ->get(['profile', 'tickets', 'captured_at']);

            $dailyUrgency = $urgencySnaps
                ->groupBy(fn ($s) => $s->captured_at->toDateString())
                ->map(function ($daySnaps, $date) {
                    // Latest per profile within the day — avoids double-counting multiple pushes
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
        }

        return Inertia::render('Console/Dashboard', [
            'stats'         => $stats,
            'ticket_trend'  => $ticketTrend,
            'daily_urgency' => $dailyUrgency,
        ]);
    }

    private function computeStreak($snapshots): int
    {
        if ($snapshots->isEmpty()) {
            return 0;
        }

        // Unique UTC dates, sorted descending
        $dates = $snapshots
            ->map(fn ($s) => $s->captured_at->utc()->toDateString())
            ->unique()
            ->sortDesc()
            ->values();

        $today     = Carbon::now()->utc()->toDateString();
        $yesterday = Carbon::now()->utc()->subDay()->toDateString();

        // Streak must include today or yesterday to be "current"
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
