<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the AdminRevenue permission bit (512) from the system.
 *
 * Revenue moves to the Owner-only surface (/console/owner/revenue),
 * so the dedicated permission bit is no longer needed. All users
 * with the bit set (~896 admin mask) have it cleared. The feature
 * row and any tier→feature mappings are deleted.
 *
 * Rationale: separating operator-only views (revenue, global user list)
 * from team-scoped views (Admin panel) — see project memory for the
 * hybrid consolidation plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Clear bit 512 from every user's permissions column.
        DB::statement('UPDATE users SET permissions = permissions & ~512 WHERE (permissions & 512) != 0');

        // Delete tier→feature mappings pointing at admin_revenue (if any).
        $feature = DB::table('features')->where('name', 'admin_revenue')->first();
        if ($feature) {
            DB::table('tier_features')->where('feature_id', $feature->id)->delete();
            DB::table('features')->where('id', $feature->id)->delete();
        }
    }

    public function down(): void
    {
        // Irreversible: we do not restore bits we cleared, nor re-seed the feature row.
        // If you need to roll back this migration, re-run FeatureSeeder and reset
        // users.permissions manually from a backup.
    }
};
