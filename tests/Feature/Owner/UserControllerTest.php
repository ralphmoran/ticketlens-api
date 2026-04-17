<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Models\AuditLog;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['tier' => 'free', 'permissions' => 64], $attrs));
    }

    // --- Index ---

    public function test_owner_can_list_users(): void
    {
        $owner = $this->makeOwner();
        $this->makeUser(['email' => 'a@test.com']);

        $response = $this->actingAs($owner)->get('/console/owner/users');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Owner/Users/Index'));
    }

    public function test_non_owner_cannot_list_users(): void
    {
        $user = $this->makeUser(['permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/users');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_users_list_supports_email_search(): void
    {
        $owner = $this->makeOwner();
        $this->makeUser(['email' => 'findme@test.com']);
        $this->makeUser(['email' => 'other@test.com']);

        $response = $this->actingAs($owner)->get('/console/owner/users?search=findme');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Users/Index')
            ->has('users.data', 1)
        );
    }

    public function test_users_list_supports_tier_filter(): void
    {
        $owner = $this->makeOwner();
        $this->makeUser(['tier' => 'pro', 'permissions' => 71]);
        $this->makeUser(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($owner)->get('/console/owner/users?tier=pro');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Users/Index')
            ->has('users.data', 1)
        );
    }

    // --- Show ---

    public function test_owner_can_view_user_detail(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser();

        $response = $this->actingAs($owner)->get("/console/owner/users/{$user->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Owner/Users/Show'));
    }

    // --- Update (tier / permissions) ---

    public function test_owner_can_update_user_tier(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($owner)->patch("/console/owner/users/{$user->id}", [
            'tier' => 'pro',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'tier' => 'pro']);
    }

    public function test_tier_update_is_audit_logged(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser(['tier' => 'free', 'permissions' => 64]);

        $this->actingAs($owner)->patch("/console/owner/users/{$user->id}", ['tier' => 'pro']);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $user->id,
            'action'         => 'user.tier_changed',
        ]);
    }

    public function test_owner_cannot_set_invalid_tier(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser();

        $response = $this->actingAs($owner)->patch("/console/owner/users/{$user->id}", [
            'tier' => 'invalid_tier',
        ]);

        $response->assertSessionHasErrors('tier');
    }

    // --- Suspend / Restore ---

    public function test_owner_can_suspend_user(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser();

        $response = $this->actingAs($owner)->post("/console/owner/users/{$user->id}/suspend");

        $response->assertRedirect();
        $this->assertNotNull($user->fresh()->suspended_at);
    }

    public function test_suspend_is_audit_logged(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser();

        $this->actingAs($owner)->post("/console/owner/users/{$user->id}/suspend");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $user->id,
            'action'         => 'user.suspended',
        ]);
    }

    public function test_owner_can_restore_suspended_user(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser(['suspended_at' => now()]);

        $response = $this->actingAs($owner)->post("/console/owner/users/{$user->id}/restore");

        $response->assertRedirect();
        $this->assertNull($user->fresh()->suspended_at);
    }

    public function test_restore_is_audit_logged(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser(['suspended_at' => now()]);

        $this->actingAs($owner)->post("/console/owner/users/{$user->id}/restore");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $user->id,
            'action'         => 'user.restored',
        ]);
    }

    // --- Soft delete ---

    public function test_owner_can_soft_delete_user(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser();

        $response = $this->actingAs($owner)->delete("/console/owner/users/{$user->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_soft_delete_is_audit_logged(): void
    {
        $owner = $this->makeOwner();
        $user  = $this->makeUser();

        $this->actingAs($owner)->delete("/console/owner/users/{$user->id}");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $user->id,
            'action'         => 'user.deleted',
        ]);
    }

    public function test_owner_cannot_delete_themselves(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->delete("/console/owner/users/{$owner->id}");

        $response->assertStatus(422);
        $this->assertNotSoftDeleted('users', ['id' => $owner->id]);
    }
}
