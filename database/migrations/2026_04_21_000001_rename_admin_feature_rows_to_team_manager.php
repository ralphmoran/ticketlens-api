<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename admin_users → team_manage_members, admin_licenses → team_manage_seats
 * in the features table. Bit values (128, 256) are unchanged, so no users.permissions
 * update is needed — only the human-readable name and label.
 *
 * This reflects the semantic shift from "operator panel" to "Team-tier manager
 * panel" (hybrid consolidation plan, see project memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('features')
            ->where('name', 'admin_users')
            ->update([
                'name'        => 'team_manage_members',
                'label'       => 'Team: Manage Members',
                'description' => 'Invite and remove team members',
            ]);

        DB::table('features')
            ->where('name', 'admin_licenses')
            ->update([
                'name'        => 'team_manage_seats',
                'label'       => 'Team: Manage Seats',
                'description' => 'Allocate and rotate team seats',
            ]);
    }

    public function down(): void
    {
        DB::table('features')
            ->where('name', 'team_manage_members')
            ->update([
                'name'        => 'admin_users',
                'label'       => 'Admin: Users',
                'description' => 'Manage client accounts',
            ]);

        DB::table('features')
            ->where('name', 'team_manage_seats')
            ->update([
                'name'        => 'admin_licenses',
                'label'       => 'Admin: Licenses',
                'description' => 'Manage license keys',
            ]);
    }
};
