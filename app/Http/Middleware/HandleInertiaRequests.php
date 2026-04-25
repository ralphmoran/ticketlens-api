<?php

namespace App\Http\Middleware;

use App\Models\UserFeatureGrant;
use App\Services\ImpersonationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user                 = $request->user();
        $effectivePermissions = null;
        $isTeamManager        = false;
        $activeGrants         = [];

        if ($user !== null) {
            $user->load('groups');
            $effectivePermissions = app(\App\Services\PermissionService::class)->effective($user);

            // Matches EnsureTeamManager middleware predicate: manager bit AND owned group.
            // Both are required — a bit without a group is meaningless (nothing to
            // manage), a group without the bit is revoked manager access.
            $hasManagerBit = ($effectivePermissions & \App\Enums\Permission::TeamManageMembers->value) !== 0;
            $isTeamManager = $hasManagerBit && $user->isTeamManager();

            $activeGrants = UserFeatureGrant::where('user_id', $user->id)
                ->active()
                ->with('feature')
                ->get()
                ->map(fn ($g) => [
                    'label'      => $g->feature->label,
                    'expires_at' => $g->expires_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $impersonating = null;
        if ($user !== null && $request->session()->has(ImpersonationService::SESSION_KEY)) {
            $impersonating = $user->only('name', 'email');
        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user'                 => $user ? $user->only('id', 'name', 'email', 'tier', 'permissions') : null,
                'effectivePermissions' => $effectivePermissions,
                'is_owner'             => $user?->is_owner ?? false,
                'is_team_manager'      => $isTeamManager,
                'activeGrants'         => $activeGrants,
                'impersonating'        => $impersonating,
            ],
        ]);
    }
}
