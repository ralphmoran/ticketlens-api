<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The 2026_07_16_000003 backfill migration already ran once (as part of
 * RefreshDatabase's full migrate) before any user in this test existed, so
 * it was a no-op for them. These tests re-run its up()/down() directly
 * against manually-seeded "pre-existing" users to prove the actual backfill
 * behaviour: additive only, never strips an existing bit.
 */
class RecallBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_07_16_000003_backfill_recall_for_team_and_enterprise_tiers.php');
    }

    public function test_backfill_adds_recall_without_stripping_manager_or_lead_bits(): void
    {
        // team() (6783) already includes Recall going forward, so simulate a
        // user provisioned BEFORE this migration existed — has the old
        // pre-Recall team composite plus manager and lead bits, no Recall bit.
        $preRecallTeamComposite = 2687; // team() before this migration's product decision
        $managerAndLead = 384 | 1024;   // teamManagerMask | TeamViewHealth
        $user = User::factory()->create([
            'tier'        => 'team',
            'permissions' => $preRecallTeamComposite | $managerAndLead,
        ]);

        $this->migration()->up();

        $fresh = $user->fresh();
        $this->assertNotSame(0, $fresh->permissions & 4096, 'Recall bit must be added');
        $this->assertNotSame(0, $fresh->permissions & 384, 'manager bits must survive the backfill');
        $this->assertNotSame(0, $fresh->permissions & 1024, 'lead bit must survive the backfill');
        $this->assertSame($preRecallTeamComposite | $managerAndLead | 4096, $fresh->permissions);
    }

    public function test_backfill_skips_free_and_pro_tiers(): void
    {
        $free = User::factory()->create(['tier' => 'free', 'permissions' => 64]);
        $pro  = User::factory()->create(['tier' => 'pro', 'permissions' => 2119]);

        $this->migration()->up();

        $this->assertSame(64, $free->fresh()->permissions);
        $this->assertSame(2119, $pro->fresh()->permissions);
    }

    public function test_backfill_skips_the_owner_row(): void
    {
        $owner = User::factory()->create(['tier' => 'team', 'is_owner' => true, 'permissions' => 2687]);

        $this->migration()->up();

        $this->assertSame(2687, $owner->fresh()->permissions);
    }

    public function test_down_removes_only_the_recall_bit_it_added(): void
    {
        $preRecallTeamComposite = 2687;
        $managerAndLead = 384 | 1024;
        $user = User::factory()->create([
            'tier'        => 'team',
            'permissions' => $preRecallTeamComposite | $managerAndLead,
        ]);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertSame($preRecallTeamComposite | $managerAndLead, $user->fresh()->permissions);
    }

    public function test_backfill_populates_tier_features_for_team_and_enterprise(): void
    {
        // RefreshDatabase's ephemeral test DB starts with an empty `features` table
        // (FeatureSeeder is a seeder, not a migration) — this migration's up() ran
        // once already as part of RefreshDatabase's initial migrate, found no
        // 'recall' feature row yet, and skipped the tier_features insert via its
        // own guard. Seed features now, then re-run up() to exercise that path —
        // insertOrIgnore makes this a safe, idempotent re-run.
        $this->seed(FeatureSeeder::class);
        $this->migration()->up();

        $recallFeatureId = DB::table('features')->where('name', 'recall')->value('id');

        $this->assertDatabaseHas('tier_features', ['tier' => 'team', 'feature_id' => $recallFeatureId]);
        $this->assertDatabaseHas('tier_features', ['tier' => 'enterprise', 'feature_id' => $recallFeatureId]);
        $this->assertDatabaseMissing('tier_features', ['tier' => 'pro', 'feature_id' => $recallFeatureId]);
        $this->assertDatabaseMissing('tier_features', ['tier' => 'free', 'feature_id' => $recallFeatureId]);
    }
}
