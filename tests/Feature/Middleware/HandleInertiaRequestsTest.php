<?php

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the shape of `auth` props shared by HandleInertiaRequests.
 * Phase 4 adds `auth.impersonating`; every other key must remain identical.
 */
class HandleInertiaRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_share_exposes_user_subset(): void
    {
        $user = User::factory()->create([
            'name'        => 'Alice',
            'email'       => 'alice@test.com',
            'tier'        => 'free',
            'permissions' => 1,
        ]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.name', 'Alice')
                ->where('auth.user.email', 'alice@test.com')
                ->where('auth.user.tier', 'free')
                ->where('auth.user.permissions', 1)
            );
    }

    public function test_auth_share_exposes_effective_permissions(): void
    {
        $owner = User::factory()->create(['is_owner' => true, 'permissions' => 1]);

        $this->actingAs($owner)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page->has('auth.effectivePermissions'));
    }

    public function test_auth_share_exposes_is_owner(): void
    {
        $owner    = User::factory()->create(['is_owner' => true]);
        $nonOwner = User::factory()->create(['is_owner' => false, 'permissions' => 1]);

        $this->actingAs($owner)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.is_owner', true));

        $this->actingAs($nonOwner)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.is_owner', false));
    }

    public function test_auth_share_exposes_active_grants_array(): void
    {
        $user = User::factory()->create(['permissions' => 1]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('auth.activeGrants')
                ->where('auth.activeGrants', [])
            );
    }
}
