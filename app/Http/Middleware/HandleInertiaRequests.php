<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\UserFeatureGrant;
use App\Services\ImpersonationService;
use App\Services\PermissionService;
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
        $isTeamLead           = false;
        $activeGrants         = [];

        if ($user !== null) {
            $user->load('groups');

            // Load grants once; owners have no meaningful grants (god bitmask via flag).
            $grants = $user->is_owner
                ? collect()
                : UserFeatureGrant::where('user_id', $user->id)->active()->with('feature')->get();

            $effectivePermissions = app(PermissionService::class)->effectiveWithGrants($user, $grants);
            $isTeamManager        = app(PermissionService::class)->isEffectiveTeamManager($user, $effectivePermissions);

            // Lead: has TeamViewHealth bit but is not the manager.
            // The bit is only assigned by managers to their own group members.
            $isTeamLead = !$user->is_owner && !$isTeamManager
                && ($effectivePermissions & Permission::TeamViewHealth->value) !== 0;

            $activeGrants = $grants
                ->map(fn ($g) => [
                    'label'      => $g->feature->label,
                    'expires_at' => $g->expires_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $groupId = null;
        if ($user !== null && !$user->is_owner && in_array($user->tier, ['team', 'pro'], true)) {
            $groupId = $user->groups->first()?->id;
        }

        $impersonating = null;
        if ($user !== null && $request->session()->has(ImpersonationService::SESSION_KEY)) {
            $impersonating = $user->only('name', 'email');
        }

        $can = [];
        if ($user !== null && $effectivePermissions !== null) {
            foreach (Permission::cases() as $p) {
                $can[$p->name] = ($effectivePermissions & $p->value) !== 0;
            }
        }

        return array_merge(parent::share($request), [
            'flash' => [
                'cli_token_generated' => $request->session()->get('cli_token_generated'),
                'status'              => $request->session()->get('status'),
                'success'             => $request->session()->get('success'),
                'rule_type'           => $request->session()->get('rule_type'),
            ],
            'auth' => [
                'user'                 => $user ? array_merge(
                    $user->only('id', 'name', 'email', 'tier', 'permissions'),
                    ['avatar_url' => $user->avatarUrl()],
                ) : null,
                'effectivePermissions' => $effectivePermissions,
                'is_owner'             => $user?->is_owner ?? false,
                'is_team_manager'      => $isTeamManager,
                'is_team_lead'         => $isTeamLead,
                'activeGrants'         => $activeGrants,
                'impersonating'        => $impersonating,
                'group_id'             => $groupId,
                'can'                  => $can,
            ],
        ]);
    }
}
