<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds owner_id to groups — the Team-tier manager who can invite/remove
 * members and allocate seats within this group.
 *
 * Nullable because:
 *   - Existing group rows (pre-Stage 4) have no concept of owner
 *   - Temporary ownerlessness during manager demotion/promotion
 *
 * NOT cascaded on user deletion — the app enforces "Team requires a
 * manager" at service layer. A deleted manager means the group is
 * orphaned until reassignment or group deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->foreignId('owner_id')->nullable()->after('name')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
