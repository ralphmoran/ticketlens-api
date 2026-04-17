<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TierService
{
    /**
     * Compute the permissions bitmask for a tier from the tier_features table.
     */
    public function permissionsForTier(string $tier): int
    {
        $bits = DB::table('tier_features')
            ->join('features', 'features.id', '=', 'tier_features.feature_id')
            ->where('tier_features.tier', $tier)
            ->pluck('features.bit_value');

        return $bits->reduce(fn (int $carry, int $bit) => $carry | $bit, 0);
    }

    /**
     * Set a user's permissions to their tier's current preset.
     * Wrapped in a transaction — caller should not wrap again.
     */
    public function syncUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $permissions = $this->permissionsForTier($user->tier);
            $user->update(['permissions' => $permissions]);
        });
    }

    /**
     * Bulk-sync all users on a given tier after the tier's feature set changes.
     */
    public function syncAllForTier(string $tier): void
    {
        $permissions = $this->permissionsForTier($tier);

        DB::transaction(function () use ($tier, $permissions): void {
            User::where('tier', $tier)->update(['permissions' => $permissions]);
        });
    }
}
