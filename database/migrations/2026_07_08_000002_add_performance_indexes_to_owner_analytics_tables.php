<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            // Serves non-correlated range scans that filter has_metadata + created_at
            // with no user_id predicate: cliLogsQuery(), commandsPerUser(),
            // featurePenetration(), DashboardController's activeUsers count.
            $table->index(['has_metadata', 'created_at'], 'usage_logs_metadata_created_idx');

            // Serves the correlated whereNotExists inner lookups in churnedAccounts(),
            // atRiskAccounts(), neverPushed() — these filter user_id (via whereColumn)
            // plus has_metadata/created_at, which the index above can't serve since
            // user_id isn't its leading column.
            $table->index(['user_id', 'has_metadata', 'created_at'], 'usage_logs_user_metadata_created_idx');
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->index('status', 'licenses_status_idx');
            $table->index('tier', 'licenses_tier_idx');
            $table->index('created_at', 'licenses_created_at_idx');
            $table->index('expires_at', 'licenses_expires_at_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'audit_logs_created_at_idx');
        });

        Schema::table('triage_snapshots', function (Blueprint $table) {
            // pushVolumePerDay()/dauWauMau() in RevenueController filter captured_at
            // alone (no user_id predicate) — the existing triage_snapshots_user_captured_idx
            // (user_id, captured_at) can't serve that shape since user_id isn't constrained.
            $table->index('captured_at', 'triage_snapshots_captured_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropIndex('usage_logs_metadata_created_idx');
            $table->dropIndex('usage_logs_user_metadata_created_idx');
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropIndex('licenses_status_idx');
            $table->dropIndex('licenses_tier_idx');
            $table->dropIndex('licenses_created_at_idx');
            $table->dropIndex('licenses_expires_at_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_created_at_idx');
        });

        Schema::table('triage_snapshots', function (Blueprint $table) {
            $table->dropIndex('triage_snapshots_captured_at_idx');
        });
    }
};
