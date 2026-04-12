<?php

namespace App\Http\Controllers\Console;

use App\Models\UsageLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DigestsController
{
    public function index(Request $request): Response
    {
        $digests = UsageLog::where('user_id', $request->user()->id)
            ->where('action', 'digest_send')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'ticket_key', 'tokens_used', 'created_at']);

        return Inertia::render('Console/Digests', [
            'digests' => $digests,
        ]);
    }
}
