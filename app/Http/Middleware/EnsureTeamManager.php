<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates /console/admin/* routes on two conditions:
 *   1. User has the TeamManageMembers bit (128) in effective permissions.
 *   2. User owns a group (groups.owner_id = user.id).
 *
 * Both are required — a bit without a group is meaningless (nothing to
 * manage), a group without the bit is revoked manager access (demoted).
 *
 * Fails-closed: 403 when either condition fails, for both JSON and
 * Inertia requests (redirects non-JSON to /console/dashboard).
 */
class EnsureTeamManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect('/console/login');
        }

        $permissions    = app(\App\Services\PermissionService::class)->effective($user);
        $hasBit         = ($permissions & Permission::TeamManageMembers->value) !== 0;
        $ownsGroup      = $user->ownedGroup()->exists();

        if (! $hasBit || ! $ownsGroup) {
            if ($request->expectsJson()) {
                abort(403, 'Team manager access required.');
            }
            return redirect('/console/dashboard');
        }

        return $next($request);
    }
}
