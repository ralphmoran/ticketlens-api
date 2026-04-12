<?php

namespace App\Http\Controllers\Console;

use App\Models\UsageLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $checks = UsageLog::where('user_id', $user->id)
            ->where('action', 'compliance_check')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'ticket_key', 'tokens_used', 'created_at']);

        // Monthly usage count (for Free tier limit display: 3/month)
        $monthlyCount = UsageLog::where('user_id', $user->id)
            ->where('action', 'compliance_check')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $monthlyLimit = $user->tier === 'free' ? 3 : null; // null = unlimited

        return Inertia::render('Console/Compliance', [
            'checks'       => $checks,
            'monthlyCount' => $monthlyCount,
            'monthlyLimit' => $monthlyLimit,
        ]);
    }
}
