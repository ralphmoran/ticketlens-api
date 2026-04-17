<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $query = AuditLog::with(['actor', 'targetUser'])->latest();

        if ($action = $request->string('action')->trim()->value()) {
            $query->where('action', $action);
        }

        if ($actorId = $request->integer('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        if ($targetId = $request->integer('target_user_id')) {
            $query->where('target_user_id', $targetId);
        }

        return Inertia::render('Console/Owner/Audit/Index', [
            'logs'    => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('action', 'actor_id', 'target_user_id'),
        ]);
    }
}
