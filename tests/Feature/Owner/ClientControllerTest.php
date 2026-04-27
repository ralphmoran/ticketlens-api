<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Models\AuditLog;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function makeClient(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['tier' => 'free', 'permissions' => 64], $attrs));
    }

    // --- Index ---

    public function test_owner_can_list_clients(): void
    {
        $owner = $this->makeOwner();
        $this->makeClient(['email' => 'a@test.com']);

        $response = $this->actingAs($owner)->get('/console/owner/clients');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Owner/Clients/Index'));
    }

    public function test_non_owner_cannot_list_clients(): void
    {
        $user = $this->makeClient(['permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/clients');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_clients_list_supports_email_search(): void
    {
        $owner = $this->makeOwner();
        $this->makeClient(['email' => 'findme@test.com']);
        $this->makeClient(['email' => 'other@test.com']);

        $response = $this->actingAs($owner)->get('/console/owner/clients?search=findme');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Clients/Index')
            ->has('clients.data', 1)
        );
    }

    public function test_clients_list_supports_tier_filter(): void
    {
        $owner = $this->makeOwner();
        $this->makeClient(['tier' => 'pro', 'permissions' => 71]);
        $this->makeClient(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($owner)->get('/console/owner/clients?tier=pro');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Clients/Index')
            ->has('clients.data', 1)
        );
    }

    // --- Show ---

    public function test_owner_can_view_client_detail(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient();

        $response = $this->actingAs($owner)->get("/console/owner/clients/{$client->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Owner/Clients/Show'));
    }

    public function test_show_excludes_admin_prefixed_features_from_grant_dropdown(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient();
        \App\Models\Feature::create(['name' => 'schedules',           'bit_value' => 1,   'label' => 'Schedules', 'sort_order' => 10]);
        \App\Models\Feature::create(['name' => 'admin_users',         'bit_value' => 128, 'label' => 'Admin: Users', 'sort_order' => 80]);
        \App\Models\Feature::create(['name' => 'team_manage_members', 'bit_value' => 130, 'label' => 'Team: Manage Members', 'sort_order' => 85]);

        $response = $this->actingAs($owner)->get("/console/owner/clients/{$client->id}");

        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Clients/Show')
            ->where('features', fn ($features) => collect($features)->every(fn ($f) => !str_starts_with($f['name'] ?? '', 'admin_')))
        );
    }

    // --- Update (tier / permissions) ---

    public function test_owner_can_update_client_tier(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($owner)->patch("/console/owner/clients/{$client->id}", [
            'tier' => 'pro',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $client->id, 'tier' => 'pro']);
    }

    public function test_tier_update_is_audit_logged(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient(['tier' => 'free', 'permissions' => 64]);

        $this->actingAs($owner)->patch("/console/owner/clients/{$client->id}", ['tier' => 'pro']);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $client->id,
            'action'         => 'user.tier_changed',
        ]);
    }

    public function test_owner_cannot_set_invalid_tier(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient();

        $response = $this->actingAs($owner)->patch("/console/owner/clients/{$client->id}", [
            'tier' => 'invalid_tier',
        ]);

        $response->assertSessionHasErrors('tier');
    }

    // --- Suspend / Restore ---

    public function test_owner_can_suspend_client(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient();

        $response = $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/suspend");

        $response->assertRedirect();
        $this->assertNotNull($client->fresh()->suspended_at);
    }

    public function test_suspend_is_audit_logged(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient();

        $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/suspend");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $client->id,
            'action'         => 'user.suspended',
        ]);
    }

    public function test_owner_can_restore_suspended_client(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient(['suspended_at' => now()]);

        $response = $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/restore");

        $response->assertRedirect();
        $this->assertNull($client->fresh()->suspended_at);
    }

    public function test_restore_is_audit_logged(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient(['suspended_at' => now()]);

        $this->actingAs($owner)->post("/console/owner/clients/{$client->id}/restore");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $client->id,
            'action'         => 'user.restored',
        ]);
    }

    // --- Soft delete ---

    public function test_owner_can_soft_delete_client(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient();

        $response = $this->actingAs($owner)->delete("/console/owner/clients/{$client->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $client->id]);
    }

    public function test_soft_delete_is_audit_logged(): void
    {
        $owner  = $this->makeOwner();
        $client = $this->makeClient();

        $this->actingAs($owner)->delete("/console/owner/clients/{$client->id}");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $client->id,
            'action'         => 'user.deleted',
        ]);
    }

    public function test_owner_cannot_delete_themselves(): void
    {
        // The owner is_owner guard fires first (403, "platform owner is protected"),
        // which subsumes the older self-delete guard (422). Both protections still
        // exist — the self-delete guard remains as defense in depth for any future
        // non-owner admin path that calls into the destroy action.
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->delete("/console/owner/clients/{$owner->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('users', ['id' => $owner->id]);
    }

    // --- Owner-target protection: the platform owner cannot be suspended,
    //     deleted, tier-changed, or otherwise mutated through the owner panel.
    //     Tests use a raw DB write to fabricate a second is_owner row so the
    //     controller's guard is exercised independently of the model's
    //     singleton invariant (verified separately in SingleOwnerInvariantTest).

    private function fabricateProtectedOwner(): User
    {
        $protected = User::factory()->create(['is_owner' => false, 'tier' => 'free']);
        \DB::table('users')->where('id', $protected->id)->update(['is_owner' => true]);

        return $protected->fresh();
    }

    public function test_cannot_suspend_owner_target(): void
    {
        $owner            = $this->makeOwner();
        $protectedOwner   = $this->fabricateProtectedOwner();

        $response = $this->actingAs($owner)->post("/console/owner/clients/{$protectedOwner->id}/suspend");

        $response->assertStatus(403);
        $this->assertNull($protectedOwner->fresh()->suspended_at);
    }

    public function test_cannot_delete_owner_target(): void
    {
        $owner          = $this->makeOwner();
        $protectedOwner = $this->fabricateProtectedOwner();

        $response = $this->actingAs($owner)->delete("/console/owner/clients/{$protectedOwner->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('users', ['id' => $protectedOwner->id]);
    }

    public function test_cannot_change_owner_target_tier(): void
    {
        $owner          = $this->makeOwner();
        $protectedOwner = $this->fabricateProtectedOwner();
        $original       = $protectedOwner->tier;

        $response = $this->actingAs($owner)->patch("/console/owner/clients/{$protectedOwner->id}", [
            'tier' => 'team',
        ]);

        $response->assertStatus(403);
        $this->assertSame($original, $protectedOwner->fresh()->tier);
    }

    public function test_cannot_restore_owner_target(): void
    {
        // Defensive: even if a future code path soft-suspends the owner row,
        // the restore endpoint must not silently re-activate it.
        $owner          = $this->makeOwner();
        $protectedOwner = $this->fabricateProtectedOwner();
        \DB::table('users')->where('id', $protectedOwner->id)->update(['suspended_at' => now()]);

        $response = $this->actingAs($owner)->post("/console/owner/clients/{$protectedOwner->id}/restore");

        $response->assertStatus(403);
        $this->assertNotNull($protectedOwner->fresh()->suspended_at);
    }

    // --- Route name verification (impersonation stop redirects here) ---

    public function test_clients_show_route_name_is_console_owner_clients_show(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('console.owner.clients.show'));
    }
}
