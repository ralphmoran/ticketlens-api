<?php

namespace Tests\Unit;

use App\Models\Feature;
use App\Models\User;
use App\Models\UserFeatureGrant;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PermissionService();
    }

    private function makeFeature(int $bit): Feature
    {
        return Feature::create([
            'name'       => "feature_{$bit}",
            'bit_value'  => $bit,
            'label'      => "Feature {$bit}",
            'sort_order' => $bit,
        ]);
    }

    // ---- LOCK: pins existing effective() behaviour before Phase 3 changes ----

    public function test_effective_with_no_grants_returns_user_or_group_bits(): void
    {
        $user = User::factory()->create(['permissions' => 0b0001]);
        $user->load('groups');

        $this->assertSame(0b0001, $this->service->effective($user));
    }

    public function test_effective_without_grants_ors_user_and_group_permissions(): void
    {
        $user = User::factory()->create(['permissions' => 0b0001]);
        // No groups — group contribution is 0
        $user->load('groups');

        $this->assertSame(0b0001, $this->service->effective($user));
    }

    public function test_can_returns_true_when_bit_present(): void
    {
        $user = User::factory()->create(['permissions' => 0b0011]);
        $user->load('groups');

        $this->assertTrue($this->service->can($user, 0b0001));
        $this->assertTrue($this->service->can($user, 0b0010));
    }

    public function test_can_returns_false_when_bit_absent(): void
    {
        $user = User::factory()->create(['permissions' => 0b0001, 'is_owner' => false]);
        $user->load('groups');

        $this->assertFalse($this->service->can($user, 0b0010));
    }

    // ---- Owner god-mode short-circuit ----

    public function test_can_returns_true_for_owner_regardless_of_missing_bit(): void
    {
        $owner = User::factory()->create(['permissions' => 0, 'is_owner' => true]);
        $owner->load('groups');

        $this->assertTrue($this->service->can($owner, 0b0001));
        $this->assertTrue($this->service->can($owner, 0b1000_0000));
    }

    public function test_can_returns_true_for_owner_even_with_zero_permissions_and_no_groups(): void
    {
        $owner = User::factory()->create(['permissions' => 0, 'is_owner' => true]);
        $owner->load('groups');

        $this->assertTrue($this->service->can($owner, 0b1111_1111));
    }

    public function test_effective_for_owner_returns_all_bits_set(): void
    {
        $owner = User::factory()->create(['permissions' => 0, 'is_owner' => true]);
        $owner->load('groups');

        // ~0 in PHP is the inverted bit pattern of 0; for our purposes the contract
        // is: effective bitmask AND any test bit must be non-zero.
        $effective = $this->service->effective($owner);

        $this->assertNotSame(0, $effective & 0b0001);
        $this->assertNotSame(0, $effective & 0b1000_0000);
    }

    // ---- RED→GREEN: grant bits added to effective() ----

    public function test_effective_includes_active_grant_bits(): void
    {
        $owner   = User::factory()->create(['is_owner' => true]);
        $user    = User::factory()->create(['permissions' => 0b0001]);
        $feature = $this->makeFeature(0b0010);

        UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
            'expires_at' => null,
        ]);

        $user->load('groups');

        $this->assertSame(0b0011, $this->service->effective($user));
    }

    public function test_effective_excludes_expired_grant_bits(): void
    {
        $owner   = User::factory()->create(['is_owner' => true]);
        $user    = User::factory()->create(['permissions' => 0b0001]);
        $feature = $this->makeFeature(0b0010);

        UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
            'expires_at' => now()->subHour(),
        ]);

        $user->load('groups');

        $this->assertSame(0b0001, $this->service->effective($user));
    }

    public function test_effective_excludes_revoked_grant_bits(): void
    {
        $owner   = User::factory()->create(['is_owner' => true]);
        $user    = User::factory()->create(['permissions' => 0b0001]);
        $feature = $this->makeFeature(0b0010);

        $grant = UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
            'expires_at' => null,
        ]);
        // revoked_at is not mass-assignable — use query builder as the job/controller would
        UserFeatureGrant::where('id', $grant->id)->update(['revoked_at' => now()]);

        $user->load('groups');

        $this->assertSame(0b0001, $this->service->effective($user));
    }

    public function test_effective_ors_multiple_active_grants(): void
    {
        $owner    = User::factory()->create(['is_owner' => true]);
        $user     = User::factory()->create(['permissions' => 0b0001]);
        $featureA = $this->makeFeature(0b0010);
        $featureB = $this->makeFeature(0b0100);

        UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $featureA->id,
            'granted_by' => $owner->id,
        ]);
        UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $featureB->id,
            'granted_by' => $owner->id,
        ]);

        $user->load('groups');

        $this->assertSame(0b0111, $this->service->effective($user));
    }
}
