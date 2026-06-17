<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Owner account protection invariants.
 *
 * Covers four layers:
 *   1. Model-level deleting/forceDeleting events (app-path accidents)
 *   2. Recovery seeder restores owner when absent
 *   3. Reset command truncates non-owner data, preserves owner + reference data
 *   4. Reset command is blocked on production
 */
class OwnerProtectionTest extends TestCase
{
    use RefreshDatabase;

    // ── Model layer ──────────────────────────────────────────────────────────

    public function test_soft_deleting_owner_is_blocked(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/owner account/i');

        $owner->delete();
    }

    public function test_force_deleting_owner_is_blocked(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/owner account/i');

        $owner->forceDelete();
    }

    public function test_soft_deleting_non_owner_is_allowed(): void
    {
        $user = User::factory()->create(['is_owner' => false]);

        $user->delete();

        $this->assertSoftDeleted($user);
    }

    public function test_force_deleting_non_owner_is_allowed(): void
    {
        $user = User::factory()->create(['is_owner' => false]);

        $user->forceDelete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    // ── Recovery seeder ──────────────────────────────────────────────────────

    public function test_owner_recovery_seeder_creates_owner_when_absent(): void
    {
        $this->assertDatabaseMissing('users', ['is_owner' => true]);

        $this->artisan('db:seed', ['--class' => 'OwnerRecoverySeeder'])->assertSuccessful();

        $this->assertDatabaseHas('users', ['is_owner' => true]);
    }

    public function test_owner_recovery_seeder_is_idempotent(): void
    {
        $this->artisan('db:seed', ['--class' => 'OwnerRecoverySeeder'])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => 'OwnerRecoverySeeder'])->assertSuccessful();

        $this->assertSame(1, User::where('is_owner', true)->count());
    }

    // ── Reset command ─────────────────────────────────────────────────────────

    public function test_reset_command_removes_non_owner_users(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        User::factory()->count(3)->create(['is_owner' => false]);

        $this->assertSame(4, User::withTrashed()->count());

        $this->artisan('db:reset-to-owner', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, User::withTrashed()->count());
        $this->assertTrue(User::first()->is_owner);
    }

    public function test_reset_command_preserves_features_and_tier_features(): void
    {
        $this->artisan('db:seed', ['--class' => 'FeatureSeeder'])->assertSuccessful();
        $featureCount     = DB::table('features')->count();
        $tierFeatureCount = DB::table('tier_features')->count();

        User::factory()->create(['is_owner' => true]);

        $this->artisan('db:reset-to-owner', ['--force' => true])->assertSuccessful();

        $this->assertSame($featureCount, DB::table('features')->count());
        $this->assertSame($tierFeatureCount, DB::table('tier_features')->count());
    }

    public function test_reset_command_truncates_licenses_and_usage_data(): void
    {
        User::factory()->create(['is_owner' => true]);

        DB::table('licenses')->insert([
            'user_id'        => User::factory()->create()->id,
            'lemon_key_hash' => hash('sha256', 'TEST-KEY-001'),
            'tier'           => 'pro',
            'status'         => 'active',
            'expires_at'     => now()->addYear(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->artisan('db:reset-to-owner', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('licenses')->count());
    }

    public function test_reset_command_is_blocked_on_production(): void
    {
        $this->app['config']->set('app.env', 'production');

        $this->artisan('db:reset-to-owner', ['--force' => true])
            ->assertFailed();
    }
}
