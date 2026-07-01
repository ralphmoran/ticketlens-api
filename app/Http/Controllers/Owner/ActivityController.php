<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(): Response
    {
        $rows = DB::table('usage_logs')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['user_id', 'action', 'ticket_key', 'tokens_used', 'metadata', 'created_at'])
            ->toArray();

        return Inertia::render('Console/Owner/Activity', [
            'rows' => $rows,
        ]);
    }
}
