<?php

namespace App\Console\Commands;

use Database\Seeders\FeatureSeeder;
use Database\Seeders\OwnerRecoverySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Truncates all non-reference, non-owner data for a clean testing baseline.
 *
 * Keeps: users.is_owner=true, features, tier_features.
 * Clears everything else in FK-safe order with checks disabled.
 *
 * Blocked on APP_ENV=production. Requires --force or interactive confirmation.
 *
 * Usage: php artisan db:reset-to-owner [--force]
 */
class DbResetToOwner extends Command
{
    protected $signature   = 'db:reset-to-owner {--force : Skip confirmation prompt}';
    protected $description = 'Reset DB to owner-only state for testing (local/staging only)';

    // Truncated in this order — children before parents — with FK checks disabled.
    private const TRUNCATE = [
        'impersonation_sessions',
        'user_feature_grants',
        'user_ai_providers',
        'cli_tokens',
        'usage_logs',
        'triage_snapshots',
        'audit_logs',
        'sent_alert_logs',
        'custom_alert_rules',
        'alert_settings',
        'slack_digest_schedules',
        'slack_integrations',
        'digest_schedules',
        'workflow_rules',
        'tracker_profiles',
        'brief_templates',
        'licenses',
        'group_user',
        'groups',
        'sessions',
        'password_reset_tokens',
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
    ];

    public function handle(): int
    {
        if (config('app.env') === 'production') {
            $this->error('This command is not available in production.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'This will DELETE all users, licenses, tokens and logs — keeping only the owner account. Continue?'
        )) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $this->info('Resetting database to owner-only state…');

        $isMySQL = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        if ($isMySQL) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        foreach (self::TRUNCATE as $table) {
            DB::table($table)->truncate();
        }

        // Hard-delete all non-owner users (including soft-deleted rows).
        // Query builder path is intentional: FK checks are already disabled
        // and we need to clear soft-deleted rows that Eloquent scopes would hide.
        DB::table('users')->where('is_owner', false)->delete();

        if ($isMySQL) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // Re-seed reference data (idempotent).
        $this->call(FeatureSeeder::class);
        $this->call(OwnerRecoverySeeder::class);

        $this->info('Done. Owner account and reference data intact.');

        return self::SUCCESS;
    }
}
