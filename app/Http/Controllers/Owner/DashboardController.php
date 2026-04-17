<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Console/Owner/Dashboard', [
            'stats' => [
                'total_users'     => User::count(),
                'suspended_users' => User::whereNotNull('suspended_at')->count(),
                'recent_actions'  => AuditLog::latest()->limit(5)->with(['actor', 'targetUser'])->get(),
            ],
        ]);
    }
}
