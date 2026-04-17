<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Models\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class TierControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
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

    public function test_owner_can_view_tier_feature_matrix(): void
    {
        $owner = $this->makeOwner();
        $this->seedFeature('schedules', 1);

        $response = $this->actingAs($owner)->get('/console/owner/tiers');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Owner/Tiers/Index'));
    }

    public function test_non_owner_cannot_view_tiers(): void
    {
        $user = User::factory()->create(['permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/tiers');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_owner_can_add_feature_to_tier(): void
    {
        $owner   = $this->makeOwner();
        $feature = $this->seedFeature('schedules', 1);

        $response = $this->actingAs($owner)->post('/console/owner/tiers/pro/features', [
            'feature_id' => $feature->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tier_features', [
            'tier'       => 'pro',
            'feature_id' => $feature->id,
        ]);
    }

    public function test_adding_feature_to_tier_syncs_user_permissions(): void
    {
        $owner   = $this->makeOwner();
        $feature = $this->seedFeature('schedules', 1);
        $proUser = User::factory()->create(['tier' => 'pro', 'permissions' => 0]);

        $this->actingAs($owner)->post('/console/owner/tiers/pro/features', [
            'feature_id' => $feature->id,
        ]);

        $this->assertEquals(1, $proUser->fresh()->permissions);
    }

    public function test_adding_feature_is_audit_logged(): void
    {
        $owner   = $this->makeOwner();
        $feature = $this->seedFeature('schedules', 1);

        $this->actingAs($owner)->post('/console/owner/tiers/pro/features', [
            'feature_id' => $feature->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $owner->id,
            'action'   => 'tier.feature_added',
        ]);
    }

    public function test_owner_can_remove_feature_from_tier(): void
    {
        $owner   = $this->makeOwner();
        $feature = $this->seedFeature('schedules', 1);

        DB::table('tier_features')->insert([
            'tier'       => 'pro',
            'feature_id' => $feature->id,
        ]);

        $response = $this->actingAs($owner)->delete("/console/owner/tiers/pro/features/{$feature->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('tier_features', [
            'tier'       => 'pro',
            'feature_id' => $feature->id,
        ]);
    }

    public function test_removing_feature_syncs_user_permissions(): void
    {
        $owner   = $this->makeOwner();
        $feature = $this->seedFeature('schedules', 1);
        $proUser = User::factory()->create(['tier' => 'pro', 'permissions' => 1]);

        DB::table('tier_features')->insert([
            'tier'       => 'pro',
            'feature_id' => $feature->id,
        ]);

        $this->actingAs($owner)->delete("/console/owner/tiers/pro/features/{$feature->id}");

        $this->assertEquals(0, $proUser->fresh()->permissions);
    }

    public function test_removing_feature_is_audit_logged(): void
    {
        $owner   = $this->makeOwner();
        $feature = $this->seedFeature('schedules', 1);
        DB::table('tier_features')->insert(['tier' => 'pro', 'feature_id' => $feature->id]);

        $this->actingAs($owner)->delete("/console/owner/tiers/pro/features/{$feature->id}");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $owner->id,
            'action'   => 'tier.feature_removed',
        ]);
    }

    public function test_cannot_add_feature_to_invalid_tier(): void
    {
        $owner   = $this->makeOwner();
        $feature = $this->seedFeature('schedules', 1);

        $response = $this->actingAs($owner)->post('/console/owner/tiers/bogus/features', [
            'feature_id' => $feature->id,
        ]);

        $response->assertStatus(404);
    }
}
