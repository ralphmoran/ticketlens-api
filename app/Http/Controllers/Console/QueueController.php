<?php

namespace App\Http\Controllers\Console;

use App\Models\TriageSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QueueController
{
    public function index(Request $request): Response
    {
        $perPage = max(10, min(100, (int) $request->get('per_page', 10)));

        $snapshots = TriageSnapshot::where('user_id', $request->user()->id)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'profile', 'tickets', 'ticket_count', 'captured_at', 'updated_at']);

        return Inertia::render('Console/Queue', [
            'snapshots' => $snapshots,
        ]);
    }
}
