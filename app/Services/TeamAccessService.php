<?php

namespace App\Services;

use App\Exceptions\InsufficientSeats;
use App\Exceptions\InvalidGrantRecipient;
use App\Models\Feature;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
use App\Models\UserFeatureGrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner-grantable Team Access: lets the owner give a Free/Pro client a Group
 * plus invite-ability, independent of their billing tier — a reusable
 * pattern for future owner-managed paid add-ons.
 *
 * Deliberately distinct from LicenseIssuanceService::bootstrapTeamGroup(),
 * which is Team/Enterprise-only and bakes manager bits + tier directly into
 * users.tier/permissions. This service never touches either column — access
 * lives entirely in UserFeatureGrant (revocable, expirable, survives a tier
 * resync) and a dedicated License row flagged granted_by_owner_as_addon so
 * revenue reporting can exclude comped seats from MRR math.
 */
class TeamAccessService
{
    private const MANAGER_FEATURE_NAMES = ['team_manage_members', 'team_manage_seats'];
    private const EXCLUDED_TIERS        = ['team', 'enterprise'];
    private const MIN_SEATS             = 2;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Idempotent: returns the user's existing group if one exists, otherwise
     * creates one and attaches them as its first member. Never mutates tier
     * or permissions — callers layer that on if they need to.
     */
    public function ensureGroupExists(User $user): Group
    {
        return Group::createForOwner($user);
    }

    public function grant(User $owner, User $recipient, int $seats, ?Carbon $expiresAt = null): void
    {
        if ($recipient->is_owner) {
            throw new InvalidGrantRecipient('Team Access cannot be granted to the platform owner.');
        }

        if (in_array($recipient->tier, self::EXCLUDED_TIERS, true)) {
            throw new InvalidGrantRecipient('Team/Enterprise clients already have team capability via their tier.');
        }

        if ($seats < self::MIN_SEATS) {
            throw new InsufficientSeats($seats);
        }

        // A rank-and-file member of someone else's group must never also
        // become a group owner — User::team()/groups() assume exactly one
        // membership, and a second would make it ambiguous which group's
        // data (e.g. Jira config) the user sees.
        $belongsToAnotherGroup = $recipient->groups()
            ->where('groups.id', '!=', $recipient->ownedGroup?->id)
            ->exists();
        if ($belongsToAnotherGroup) {
            throw new InvalidGrantRecipient('This client already belongs to another team — Team Access cannot be granted.');
        }

        DB::transaction(function () use ($owner, $recipient, $seats, $expiresAt): void {
            $this->ensureGroupExists($recipient);
            $this->upsertAddonLicense($owner, $recipient, $seats, $expiresAt);

            foreach (self::MANAGER_FEATURE_NAMES as $featureName) {
                $this->upsertManagerGrant($owner, $recipient, $featureName, $expiresAt);
            }
        });

        $this->audit->log(
            actor: $owner,
            action: 'team_access.granted',
            target: $recipient,
            metadata: ['seats' => $seats, 'expires_at' => $expiresAt?->toIso8601String()],
        );
    }

    public function revoke(User $owner, User $recipient): void
    {
        DB::transaction(function () use ($recipient): void {
            UserFeatureGrant::whereIn('feature_id', Feature::whereIn('name', self::MANAGER_FEATURE_NAMES)->pluck('id'))
                ->where('user_id', $recipient->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            License::where('user_id', $recipient->id)
                ->where('granted_by_owner_as_addon', true)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);
        });

        $this->audit->log(
            actor: $owner,
            action: 'team_access.revoked',
            target: $recipient,
        );
    }

    /**
     * Manager handoff for a grant-based Team Access owner (MembersController::
     * promote()'s counterpart to the raw-bit swap it already does for real
     * Team/Enterprise managers). Unconditional: always checks both feature
     * grants and always revokes/regrants both, regardless of which the old
     * owner actually held — never "detect which one, act on that one," so no
     * mixed state (bit stripped but grant lingering, or vice versa) can occur.
     * A no-op for a real Team/Enterprise manager, who has no grants to move.
     *
     * The new owner receives EXACTLY team_manage_members + team_manage_seats,
     * carrying the OLD owner's expires_at verbatim (including null/permanent) —
     * never defaulting to unbounded access the old owner didn't have.
     *
     * Caller-transactional: intended to run inside the caller's existing
     * DB::transaction() (the group owner_id swap), not its own.
     */
    public function transferManagerGrant(User $oldOwner, User $newOwner): void
    {
        foreach (self::MANAGER_FEATURE_NAMES as $featureName) {
            $feature = Feature::where('name', $featureName)->first();
            if ($feature === null) {
                continue;
            }

            $oldGrant = UserFeatureGrant::where('user_id', $oldOwner->id)
                ->where('feature_id', $feature->id)
                ->active()
                ->first();

            if ($oldGrant === null) {
                continue;
            }

            $expiresAt = $oldGrant->expires_at;
            // Query-builder update, not $oldGrant->update() — revoked_at isn't
            // in UserFeatureGrant::$fillable (mass-assignment guard would
            // silently drop it on an instance call; a query-builder update
            // bypasses that guard entirely, matching GrantController::destroy()'s
            // existing revoke pattern).
            UserFeatureGrant::where('id', $oldGrant->id)->update(['revoked_at' => now()]);

            UserFeatureGrant::create([
                'user_id'    => $newOwner->id,
                'feature_id' => $feature->id,
                'granted_by' => $oldOwner->id,
                'expires_at' => $expiresAt,
            ]);
        }
    }

    /**
     * Reuses a prior addon license (from an earlier grant/revoke cycle) if one
     * exists — reactivating it rather than accumulating a new row every cycle.
     * Never touches any license that isn't already flagged as this service's
     * own addon — a real purchased/owner-issued license is out of reach.
     */
    private function upsertAddonLicense(User $owner, User $recipient, int $seats, ?Carbon $expiresAt): void
    {
        $addonLicense = License::where('user_id', $recipient->id)
            ->where('granted_by_owner_as_addon', true)
            ->latest()
            ->first();

        if ($addonLicense === null) {
            License::create([
                'user_id'                   => $recipient->id,
                'issued_by_user_id'         => $owner->id,
                // No real purchase or raw key exists for an addon license — a
                // synthetic, unique hash satisfies the NOT NULL/unique column
                // without implying a CLI-activatable key was ever issued.
                'lemon_key_hash'            => hash('sha256', 'team-access-addon:' . $recipient->id . ':' . Str::uuid()),
                'tier'                      => $recipient->tier,
                'seats'                     => $seats,
                'status'                    => 'active',
                'expires_at'                => $expiresAt,
                'granted_by_owner_as_addon' => true,
            ]);
            return;
        }

        $addonLicense->update([
            'seats'      => $seats,
            'status'     => 'active',
            'expires_at' => $expiresAt,
        ]);
    }

    private function upsertManagerGrant(User $owner, User $recipient, string $featureName, ?Carbon $expiresAt): void
    {
        $feature = Feature::where('name', $featureName)->firstOrFail();

        $existing = UserFeatureGrant::where('user_id', $recipient->id)
            ->where('feature_id', $feature->id)
            ->active()
            ->first();

        if ($existing !== null) {
            $existing->update(['expires_at' => $expiresAt]);
            return;
        }

        UserFeatureGrant::create([
            'user_id'    => $recipient->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
            'expires_at' => $expiresAt,
        ]);
    }
}
