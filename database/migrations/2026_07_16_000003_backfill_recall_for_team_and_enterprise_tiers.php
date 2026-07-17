<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recall becomes always-on for Team/Enterprise tiers (product decision —
 * previously owner-opt-in via tier_features, never actually defaulted on).
 *
 * Two things must both happen for this to take effect on ALREADY-provisioned
 * users — tier_features alone is a display/sync source, not a live permission
 * source (only TierService::syncUser()/syncAllForTier() read it, and neither
 * runs from a migration):
 *
 *   1. Backfill tier_features so future syncs pick Recall up for team/enterprise.
 *   2. Bitwise-OR the Recall bit (4096) directly onto every existing team/
 *      enterprise user's stored permissions.
 *
 * Deliberately NOT calling TierService::syncAllForTier('team'/'enterprise') —
 * that method does a full preset OVERWRITE and would strip every existing
 * team manager's teamManagerMask (128/256) and every lead's TeamViewHealth
 * (1024), since neither is in the team()/enterprise() preset. A scoped
 * bitwise-OR only ever adds the one bit this migration owns.
 */
return new class extends Migration
{
    private const RECALL_BIT = 4096;
    private const TIERS      = ['team', 'enterprise'];

    public function up(): void
    {
        $featureId = DB::table('features')->where('name', 'recall')->value('id');

        if ($featureId !== null) {
            foreach (self::TIERS as $tier) {
                DB::table('tier_features')->insertOrIgnore([
                    'tier'       => $tier,
                    'feature_id' => $featureId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('users')
            ->whereIn('tier', self::TIERS)
            ->where('is_owner', false)
            ->update(['permissions' => DB::raw('permissions | ' . self::RECALL_BIT)]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereIn('tier', self::TIERS)
            ->where('is_owner', false)
            ->update(['permissions' => DB::raw('permissions & ~' . self::RECALL_BIT)]);

        $featureId = DB::table('features')->where('name', 'recall')->value('id');

        if ($featureId !== null) {
            DB::table('tier_features')
                ->whereIn('tier', self::TIERS)
                ->where('feature_id', $featureId)
                ->delete();
        }
    }
};
