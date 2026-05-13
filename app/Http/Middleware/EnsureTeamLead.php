<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates routes accessible to both team leads and team managers.
 *
 * Passes when any of the following is true:
 *   - User is the platform owner (is_owner bypass)
 *   - User has TeamManageMembers bit (team manager)
 *   - User has TeamViewHealth bit (lead — assigned by their manager)
 *
 * Routes requiring manager-only access (Members, Seats, Process Metrics)
 * should continue to use EnsureTeamManager instead.
 */
class EnsureTeamLead
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect('/console/login');
        }

        if ($user->is_owner) {
            return $next($request);
        }

        $permissions = app(\App\Services\PermissionService::class)->effective($user);
        $isManager   = ($permissions & Permission::TeamManageMembers->value) !== 0;
        $isLead      = ($permissions & Permission::TeamViewHealth->value) !== 0;

        if (! $isManager && ! $isLead) {
            if ($request->expectsJson()) {
                abort(403, 'Team lead or manager access required.');
            }
            return redirect('/console/dashboard');
        }

        return $next($request);
    }
}
