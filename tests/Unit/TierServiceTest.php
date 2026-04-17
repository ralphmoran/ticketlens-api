<?php

namespace Tests\Unit;

use App\Models\Feature;
use App\Models\User;
use App\Services\TierService;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class TierServiceTest extends TestCase
{
    use RefreshDatabase;

    private TierService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TierService();
    }

    private function seedFeature(string $name, int $bit): Feature
    {
        return Feature::create([
            'name'       => $name,
            'bit_value'  => $bit,
            'label'      => ucfirst($name),
            'sort_order' => $bit,
        ]);
    }

    public function test_permissions_for_empty_tier_is_zero(): void
    {
        $this->assertEquals(0, $this->service->permissionsForTier('free'));
    }

    public function test_permissions_for_tier_ors_all_feature_bits(): void
    {
        $schedules = $this->seedFeature('schedules', 1);
        $digests   = $this->seedFeature('digests', 2);

        DB::table('tier_features')->insert([
            ['tier' => 'pro', 'feature_id' => $schedules->id],
            ['tier' => 'pro', 'feature_id' => $digests->id],
        ]);

        $this->assertEquals(3, $this->service->permissionsForTier('pro'));
    }

    public function test_sync_user_updates_permissions_to_tier_preset(): void
    {
        $feature = $this->seedFeature('schedules', 1);
        DB::table('tier_features')->insert(['tier' => 'pro', 'feature_id' => $feature->id]);

        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 0]);

        $this->service->syncUser($user);

        $this->assertEquals(1, $user->fresh()->permissions);
    }

    public function test_sync_all_for_tier_updates_every_user_on_that_tier(): void
    {
        $feature = $this->seedFeature('schedules', 1);
        DB::table('tier_features')->insert(['tier' => 'pro', 'feature_id' => $feature->id]);

        $proUser1 = User::factory()->create(['tier' => 'pro', 'permissions' => 0]);
        $proUser2 = User::factory()->create(['tier' => 'pro', 'permissions' => 0]);
        $freeUser = User::factory()->create(['tier' => 'free', 'permissions' => 0]);

        $this->service->syncAllForTier('pro');

        $this->assertEquals(1, $proUser1->fresh()->permissions);
        $this->assertEquals(1, $proUser2->fresh()->permissions);
        $this->assertEquals(0, $freeUser->fresh()->permissions); // untouched
    }

    public function test_sync_all_does_not_affect_other_tiers(): void
    {
        $this->seedFeature('schedules', 1);

        $teamUser = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $this->service->syncAllForTier('pro');

        $this->assertEquals(127, $teamUser->fresh()->permissions);
    }
}
