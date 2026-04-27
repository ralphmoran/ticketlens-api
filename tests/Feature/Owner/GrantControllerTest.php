<?php

namespace Tests\Feature\Owner;

use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\User;
use App\Models\UserFeatureGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['tier' => 'free', 'permissions' => 1], $attrs));
    }

    private function makeFeature(int $bit = 2): Feature
    {
        return Feature::create([
            'name'       => "feature_{$bit}",
            'bit_value'  => $bit,
            'label'      => "Feature {$bit}",
            'sort_order' => $bit,
        ]);
    }

    // --- Store ---

    public function test_owner_can_create_grant_without_expiry(): void
    {
        $owner   = $this->makeOwner();
        $user    = $this->makeUser();
        $feature = $this->makeFeature();

        $response = $this->actingAs($owner)->post(
            "/console/owner/clients/{$user->id}/grants",
            ['feature_id' => $feature->id],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('user_feature_grants', [
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
        ]);
        $this->assertNull(UserFeatureGrant::first()->expires_at);
    }

    public function test_owner_can_create_grant_with_expiry(): void
    {
        $owner   = $this->makeOwner();
        $user    = $this->makeUser();
        $feature = $this->makeFeature();
        $expiry  = now()->addDays(7)->toDateString();

        $this->actingAs($owner)->post(
            "/console/owner/clients/{$user->id}/grants",
            ['feature_id' => $feature->id, 'expires_at' => $expiry],
        );

        $this->assertNotNull(UserFeatureGrant::first()->expires_at);
    }

    public function test_owner_can_create_grant_with_note(): void
    {
        $owner   = $this->makeOwner();
        $user    = $this->makeUser();
        $feature = $this->makeFeature();

        $this->actingAs($owner)->post(
            "/console/owner/clients/{$user->id}/grants",
            ['feature_id' => $feature->id, 'note' => 'Pilot trial'],
        );

        $this->assertDatabaseHas('user_feature_grants', ['note' => 'Pilot trial']);
    }

    public function test_grant_creation_is_audit_logged(): void
    {
        $owner   = $this->makeOwner();
        $user    = $this->makeUser();
        $feature = $this->makeFeature();

        $this->actingAs($owner)->post(
            "/console/owner/clients/{$user->id}/grants",
            ['feature_id' => $feature->id],
        );

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $user->id,
            'action'         => 'grant.created',
        ]);
    }

    public function test_non_owner_cannot_create_grant(): void
    {
        $nonOwner = $this->makeUser(['permissions' => 1023]);
        $user     = $this->makeUser();
        $feature  = $this->makeFeature();

        $response = $this->actingAs($nonOwner)->post(
            "/console/owner/clients/{$user->id}/grants",
            ['feature_id' => $feature->id],
        );

        $response->assertRedirect('/console/dashboard');
        $this->assertDatabaseEmpty('user_feature_grants');
    }

    public function test_grant_requires_valid_feature_id(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser();

        $response = $this->actingAs($owner)->post(
            "/console/owner/clients/{$user->id}/grants",
            ['feature_id' => 9999],
        );

        $response->assertSessionHasErrors('feature_id');
    }

    public function test_grant_rejects_past_expiry_date(): void
    {
        $owner   = $this->makeOwner();
        $user    = $this->makeUser();
        $feature = $this->makeFeature();

        $response = $this->actingAs($owner)->post(
            "/console/owner/clients/{$user->id}/grants",
            ['feature_id' => $feature->id, 'expires_at' => now()->subDay()->toDateString()],
        );

        $response->assertSessionHasErrors('expires_at');
    }

    // --- Destroy (early revoke) ---

    public function test_owner_can_revoke_grant_early(): void
    {
        $owner   = $this->makeOwner();
        $user    = $this->makeUser();
        $feature = $this->makeFeature();

        $grant = UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->delete(
            "/console/owner/clients/{$user->id}/grants/{$grant->id}",
        );

        $response->assertRedirect();
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_grant_revocation_is_audit_logged(): void
    {
        $owner   = $this->makeOwner();
        $user    = $this->makeUser();
        $feature = $this->makeFeature();

        $grant = UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner)->delete("/console/owner/clients/{$user->id}/grants/{$grant->id}");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $user->id,
            'action'         => 'grant.revoked',
        ]);
    }

    public function test_non_owner_cannot_revoke_grant(): void
    {
        $owner    = $this->makeOwner();
        $nonOwner = $this->makeUser(['permissions' => 1023]);
        $user     = $this->makeUser();
        $feature  = $this->makeFeature();

        $grant = UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($nonOwner)->delete(
            "/console/owner/clients/{$user->id}/grants/{$grant->id}",
        );

        $response->assertRedirect('/console/dashboard');
        $this->assertNull($grant->fresh()->revoked_at);
    }

    public function test_revoking_already_revoked_grant_returns_404(): void
    {
        $owner   = $this->makeOwner();
        $user    = $this->makeUser();
        $feature = $this->makeFeature();

        $grant = UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
        ]);
        UserFeatureGrant::where('id', $grant->id)->update(['revoked_at' => now()]);

        $response = $this->actingAs($owner)->delete(
            "/console/owner/clients/{$user->id}/grants/{$grant->id}",
        );

        $response->assertStatus(404);
    }

    // --- Owner-target protection ---
    //   Granting features to the owner is semantically a no-op (PermissionService
    //   short-circuits before consulting grants), but the endpoint must reject the
    //   request to avoid orphaned audit/grant rows pointing at the god account.

    private function fabricateProtectedOwner(): User
    {
        $protected = $this->makeUser();
        \DB::table('users')->where('id', $protected->id)->update(['is_owner' => true]);

        return $protected->fresh();
    }

    public function test_cannot_create_grant_for_owner_target(): void
    {
        $owner          = $this->makeOwner();
        $protectedOwner = $this->fabricateProtectedOwner();
        $feature        = $this->makeFeature();

        $response = $this->actingAs($owner)->post(
            "/console/owner/clients/{$protectedOwner->id}/grants",
            ['feature_id' => $feature->id],
        );

        $response->assertStatus(403);
        $this->assertDatabaseMissing('user_feature_grants', [
            'user_id'    => $protectedOwner->id,
            'feature_id' => $feature->id,
        ]);
    }

    public function test_cannot_revoke_grant_for_owner_target(): void
    {
        $owner          = $this->makeOwner();
        $protectedOwner = $this->fabricateProtectedOwner();
        $feature        = $this->makeFeature();

        // Insert a grant directly via query builder so the (still-being-protected)
        // controller path can be tested in isolation. In practice no grant should
        // ever exist for an owner row — this proves the endpoint blocks even when
        // a stale row is present.
        $grantId = \DB::table('user_feature_grants')->insertGetId([
            'user_id'    => $protectedOwner->id,
            'feature_id' => $feature->id,
            'granted_by' => $owner->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($owner)->delete(
            "/console/owner/clients/{$protectedOwner->id}/grants/{$grantId}",
        );

        $response->assertStatus(403);
        $this->assertNull(UserFeatureGrant::find($grantId)->revoked_at);
    }
}
