<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\License;
use App\Models\TriageSnapshot;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class RevenueController extends Controller
{
    private const TIER_PRICES = ['free' => 0, 'pro' => 8, 'team' => 15, 'enterprise' => 0];

    public function index(): Response
    {
        $activeLicenses = License::where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['tier']);

        $mrr         = $activeLicenses->sum(fn ($l) => self::TIER_PRICES[$l->tier] ?? 0);
        $totalActive = $activeLicenses->count();

        $tierBreakdown = array_merge(
            ['free' => 0, 'pro' => 0, 'team' => 0, 'enterprise' => 0],
            User::selectRaw('tier, count(*) as count')->groupBy('tier')->pluck('count', 'tier')->toArray(),
        );

        $recentEvents = License::with(['user:id,email'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id', 'user_id', 'tier', 'status', 'created_at']);

        // Signups per week — last 8 weeks (1 query, grouped in PHP)
        $weekCutoff  = now()->startOfWeek()->subWeeks(7);
        $recentUsers = User::where('created_at', '>=', $weekCutoff)->get(['created_at']);

        $signupsPerWeek = collect(range(7, 0))->map(function ($weeksAgo) use ($recentUsers) {
            $start = now()->startOfWeek()->subWeeks($weeksAgo);
            $end   = $start->copy()->endOfWeek();

            return [
                'week'  => $start->toDateString(),
                'count' => $recentUsers->filter(fn ($u) => $u->created_at->between($start, $end))->count(),
            ];
        })->values()->toArray();

        // Push volume per day — last 30 days
        $pushVolumePerDay = TriageSnapshot::selectRaw('DATE(captured_at) as date, COUNT(*) as count')
            ->where('captured_at', '>=', now()->subDays(30))
            ->groupByRaw('DATE(captured_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => $row->date, 'count' => (int) $row->count])
            ->toArray();

        // DAU / WAU / MAU — unique users with at least one snapshot in last 1/7/30 days
        $dauWauMau = [
            'dau' => TriageSnapshot::where('captured_at', '>=', now()->subDay())->distinct('user_id')->count('user_id'),
            'wau' => TriageSnapshot::where('captured_at', '>=', now()->subWeek())->distinct('user_id')->count('user_id'),
            'mau' => TriageSnapshot::where('captured_at', '>=', now()->subDays(30))->distinct('user_id')->count('user_id'),
        ];

        // Top 10 teams by activity (snapshot count, last 30 days)
        $topTeamsByActivity = Group::withCount(['members as push_count' => function ($q) {
            $q->join('triage_snapshots', 'triage_snapshots.user_id', '=', 'group_user.user_id')
              ->where('triage_snapshots.captured_at', '>=', now()->subDays(30))
              ->select(\DB::raw('count(triage_snapshots.id)'));
        }])
            ->orderByDesc('push_count')
            ->limit(10)
            ->get(['id', 'name'])
            ->map(fn ($g) => ['group_id' => $g->id, 'group_name' => $g->name, 'push_count' => (int) $g->push_count])
            ->toArray();

        return Inertia::render('Console/Owner/Revenue', [
            'mrr'                  => $mrr,
            'total_active'         => $totalActive,
            'tier_breakdown'       => $tierBreakdown,
            'recent_events'        => $recentEvents,
            'signups_per_week'     => $signupsPerWeek,
            'push_volume_per_day'  => $pushVolumePerDay,
            'dau_wau_mau'          => $dauWauMau,
            'top_teams_by_activity'=> $topTeamsByActivity,
        ]);
    }
}
