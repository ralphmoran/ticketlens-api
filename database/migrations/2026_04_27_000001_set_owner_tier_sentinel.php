<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decouple the platform owner from the tier system.
 *
 * Why: tier-driven permissions used to flow into the owner row via
 * TierService::syncAllForTier, which meant the Owner panel's "Tiers & Features"
 * matrix was mutating the god account's permission bits. Owner permissions are
 * now granted exclusively by `users.is_owner=true` (PermissionService short-
 * circuit), so the tier and permissions columns become inert sentinels for
 * owner rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('is_owner', true)
            ->update([
                'tier'        => 'owner',
                'permissions' => 0,
            ]);
    }

    public function down(): void
    {
        // Best-effort restore — pre-migration value is unknown. Defaulting back
        // to `team` mirrors the original DevSeeder + Phase 1+2 owner provisioning.
        DB::table('users')
            ->where('is_owner', true)
            ->where('tier', 'owner')
            ->update(['tier' => 'team']);
    }
};
