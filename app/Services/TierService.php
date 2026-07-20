<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Feature;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TierService
{
    /**
     * Compute the permissions bitmask for a tier from the tier_features table.
     */
    public function permissionsForTier(string $tier): int
    {
        return Cache::remember("tier_permissions:{$tier}", 300, function () use ($tier): int {
            $bits = DB::table('tier_features')
                ->join('features', 'features.id', '=', 'tier_features.feature_id')
                ->where('tier_features.tier', $tier)
                ->pluck('features.bit_value');

            return $bits->reduce(fn (int $carry, int $bit) => $carry | $bit, 0);
        });
    }

    /**
     * Set a user's permissions to their tier's current preset.
     * Wrapped in a transaction — caller should not wrap again.
     *
     * Owner accounts are skipped: their permissions are granted by `is_owner=true`
     * (god mode in PermissionService) and must never be coupled to tier mutations.
     *
     * Group owners additionally get teamManagerMask() OR'd in — rank-and-file
     * seats on the same tier don't get these (Permission::teamManagerMask() doc).
     * This runs on every call so re-syncing (e.g. after a tier_features change)
     * never silently drops manager bits a group owner already earned.
     */
    public function syncUser(User $user): void
    {
        if ($user->is_owner) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $permissions = $this->permissionsForTier($user->tier);

            if ($user->ownedGroup()->exists()) {
                $permissions |= Permission::teamManagerMask();
            }

            $user->permissions = $permissions;
            $user->save();
        });
    }

    /**
     * Bulk-sync all users on a given tier after the tier's feature set changes.
     * Owner rows are excluded — see syncUser().
     *
     * Split into two updates so group owners keep teamManagerMask() — a single
     * flat UPDATE would silently strip manager bits from every team-tier group
     * owner whenever an owner edits that tier's feature bundle in the Owner Panel.
     */
    public function syncAllForTier(string $tier): void
    {
        // Flush stale cache before recomputing — this method is called after tier_features
        // change, so the cached bitmask must not be used.
        Cache::forget("tier_permissions:{$tier}");
        $permissions       = $this->permissionsForTier($tier);
        $managerPermissions = $permissions | Permission::teamManagerMask();

        DB::transaction(function () use ($tier, $permissions, $managerPermissions): void {
            User::where('tier', $tier)
                ->where('is_owner', false)
                ->whereDoesntHave('ownedGroup')
                ->update(['permissions' => $permissions]);

            User::where('tier', $tier)
                ->where('is_owner', false)
                ->whereHas('ownedGroup')
                ->update(['permissions' => $managerPermissions]);
        });
    }
}
