<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Guard against duplicate run (e.g. re-seed environments)
        if (DB::table('features')->where('name', 'workflow_rules')->exists()) {
            return;
        }

        $featureId = DB::table('features')->insertGetId([
            'name'        => 'workflow_rules',
            'bit_value'   => 2048,
            'label'       => 'Workflow Rules',
            'description' => 'Stale status detection and workflow automation rules',
            'sort_order'  => 75,
        ]);

        foreach (['pro', 'team', 'enterprise'] as $tier) {
            DB::table('tier_features')->insert([
                'tier'       => $tier,
                'feature_id' => $featureId,
            ]);
        }

        // Flush cached bitmasks so TierService recomputes on next request
        Cache::forget('tier_permissions:pro');
        Cache::forget('tier_permissions:team');
        Cache::forget('tier_permissions:enterprise');

        // Grant bit to existing users on affected tiers
        DB::table('users')
            ->whereIn('tier', ['pro', 'team', 'enterprise'])
            ->where('is_owner', false)
            ->update(['permissions' => DB::raw('permissions | 2048')]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereIn('tier', ['pro', 'team', 'enterprise'])
            ->where('is_owner', false)
            ->update(['permissions' => DB::raw('permissions & ~2048')]);

        DB::table('tier_features')
            ->whereIn('feature_id', DB::table('features')->where('name', 'workflow_rules')->pluck('id'))
            ->delete();

        DB::table('features')->where('name', 'workflow_rules')->delete();

        Cache::forget('tier_permissions:pro');
        Cache::forget('tier_permissions:team');
        Cache::forget('tier_permissions:enterprise');
    }
};
