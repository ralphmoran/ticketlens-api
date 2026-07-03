<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $query = UsageLog::with('user')
            ->where('has_metadata', 1)
            ->latest('created_at');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('ticket_key', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('email', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = min(max(1, (int) $request->input('per_page', 10)), 100);

        return Inertia::render('Console/Owner/Activity', [
            'logs'    => $query->paginate($perPage)->withQueryString(),
            'filters' => array_merge($request->only('search'), ['per_page' => $perPage]),
        ]);
    }
}
