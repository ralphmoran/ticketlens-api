<?php

namespace App\Http\Controllers\Console;

use App\Models\UsageLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SummarizeController
{
    public function index(Request $request): Response
    {
        $summaries = UsageLog::where('user_id', $request->user()->id)
            ->where('action', 'summarize')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'ticket_key', 'tokens_used', 'created_at']);

        return Inertia::render('Console/Summarize', [
            'summaries' => $summaries,
        ]);
    }
}
