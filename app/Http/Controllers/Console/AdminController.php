<?php

namespace App\Http\Controllers\Console;

use App\Models\License;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AdminController
{
    public function clients(): Response
    {
        $clients = User::with(['license:id,user_id,status,tier,expires_at'])
            ->orderBy('created_at', 'desc')
            ->paginate(25, ['id', 'name', 'email', 'tier', 'created_at']);

        return Inertia::render('Console/Admin/Clients', [
            'clients' => $clients,
        ]);
    }

    public function licenses(): Response
    {
        $licenses = License::with(['user:id,email'])
            ->orderBy('created_at', 'desc')
            ->paginate(25, ['id', 'user_id', 'tier', 'status', 'expires_at', 'created_at']);

        return Inertia::render('Console/Admin/Licenses', [
            'licenses' => $licenses,
        ]);
    }

    public function revenue(): Response
    {
        $prices = ['free' => 0, 'pro' => 8, 'team' => 15, 'enterprise' => 0];

        $activeLicenses = License::where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['tier']);

        $mrr         = $activeLicenses->sum(fn ($l) => $prices[$l->tier] ?? 0);
        $totalActive = $activeLicenses->count();

        $tierBreakdown = array_merge(
            ['free' => 0, 'pro' => 0, 'team' => 0, 'enterprise' => 0],
            User::selectRaw('tier, count(*) as count')->groupBy('tier')->pluck('count', 'tier')->toArray(),
        );

        $recentEvents = License::with(['user:id,email'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id', 'user_id', 'tier', 'status', 'created_at']);

        return Inertia::render('Console/Admin/Revenue', [
            'mrr'            => $mrr,
            'total_active'   => $totalActive,
            'tier_breakdown' => $tierBreakdown,
            'recent_events'  => $recentEvents,
        ]);
    }
}
