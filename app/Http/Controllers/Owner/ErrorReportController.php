<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ErrorReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ErrorReportController extends Controller
{
    public function index(Request $request): Response
    {
        // No actor/group join, unlike AuditController — reports are
        // anonymous by design (49e), nothing to relate to a user.
        $query = ErrorReport::latest();

        if ($cliVersion = $request->string('cli_version')->trim()->value()) {
            $query->where('cli_version', $cliVersion);
        }

        if ($profileTier = $request->string('profile_tier')->trim()->value()) {
            $query->where('profile_tier', $profileTier);
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                  ->orWhere('command', 'like', "%{$search}%");
            });
        }

        $perPage = min(max(1, (int) $request->input('per_page', 10)), 100);

        return Inertia::render('Console/Owner/ErrorReports/Index', [
            'reports' => $query->paginate($perPage)->withQueryString(),
            'filters' => array_merge($request->only('cli_version', 'profile_tier', 'search'), ['per_page' => $perPage]),
        ]);
    }
}
