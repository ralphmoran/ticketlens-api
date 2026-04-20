<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\License;
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

        return Inertia::render('Console/Owner/Revenue', [
            'mrr'            => $mrr,
            'total_active'   => $totalActive,
            'tier_breakdown' => $tierBreakdown,
            'recent_events'  => $recentEvents,
        ]);
    }
}
