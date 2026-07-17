<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\User;
use App\Models\UserFeatureGrant;
use App\Services\AuditService;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GrantController extends Controller
{
    // These two bits are never grantable through this generic endpoint —
    // granting them requires TeamAccessService's group-bootstrap +
    // dedicated-seats-license side effects (see TeamAccessController),
    // not a bare permission row.
    private const TEAM_MANAGE_FEATURE_NAMES = ['team_manage_members', 'team_manage_seats'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly TeamAccessService $teamAccess,
    ) {}

    public function store(Request $request, User $user): RedirectResponse
    {
        // Owner has god permissions via PermissionService short-circuit — grants
        // are never consulted for the owner row. Reject up-front to keep the
        // user_feature_grants table free of meaningless rows pointing at the
        // owner and to prevent confusing audit entries.
        abort_if($user->is_owner, 403, 'Feature grants cannot be applied to the platform owner.');

        $validated = $request->validate([
            'feature_id' => ['required', 'integer', 'exists:features,id'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'note'       => ['nullable', 'string', 'max:255'],
        ]);

        $feature = Feature::findOrFail($validated['feature_id']);

        abort_if(
            in_array($feature->name, self::TEAM_MANAGE_FEATURE_NAMES, true),
            422,
            'Grant Team Access via the Team Access panel, not a generic feature grant.',
        );

        $grant = UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $request->user()->id,
            'expires_at' => $validated['expires_at'] ?? null,
            'note'       => $validated['note'] ?? null,
        ]);

        // Recall requires a group to sync into — grant it one directly (no
        // seats, no invite-ability) rather than leaving a solo client's Recall
        // entitlement unusable until the owner separately grants Team Access.
        if ($feature->name === 'recall') {
            $this->teamAccess->ensureGroupExists($user);
        }

        $this->audit->logFromRequest($request, 'grant.created', $user, null, [
            'feature_id'    => $feature->id,
            'feature_label' => $feature->label,
            'expires_at'    => $validated['expires_at'] ?? null,
            'note'          => $validated['note'] ?? null,
        ]);

        return redirect()->route('console.owner.clients.show', $user);
    }

    public function destroy(Request $request, User $user, int $grantId): RedirectResponse
    {
        abort_if($user->is_owner, 403, 'Feature grants on the platform owner are not mutated through this endpoint.');

        $grant = UserFeatureGrant::where('id', $grantId)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->firstOrFail();

        UserFeatureGrant::where('id', $grant->id)->update(['revoked_at' => now()]);

        $grant->load('feature');

        $this->audit->logFromRequest($request, 'grant.revoked', $user, [
            'feature_id'    => $grant->feature?->id,
            'feature_label' => $grant->feature?->label ?? '(deleted)',
        ]);

        return redirect()->route('console.owner.clients.show', $user);
    }
}
