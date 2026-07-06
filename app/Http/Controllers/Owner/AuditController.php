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
            $query->where(function ($q) use ($action) {
                $q->where('action', 'like', "%{$action}%")
                  ->orWhereHas('actor', function ($actorQuery) use ($action) {
                      $actorQuery->where('email', 'like', "%{$action}%")
                                 ->orWhere('name', 'like', "%{$action}%");
                  })
                  ->orWhereHas('targetUser', function ($targetQuery) use ($action) {
                      $targetQuery->where('email', 'like', "%{$action}%")
                                  ->orWhere('name', 'like', "%{$action}%");
                  });
            });
        }

        if ($actorId = $request->integer('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        if ($targetId = $request->integer('target_user_id')) {
            $query->where('target_user_id', $targetId);
        }

        $perPage = min(max(1, (int) $request->input('per_page', 10)), 100);

        return Inertia::render('Console/Owner/Audit/Index', [
            'logs'    => $query->paginate($perPage)->withQueryString(),
            'filters' => array_merge($request->only('action', 'actor_id', 'target_user_id'), ['per_page' => $perPage]),
        ]);
    }
}
