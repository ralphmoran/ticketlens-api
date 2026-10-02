<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionKeepaliveTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['tier' => 'pro', 'permissions' => 71]);
    }

    public function test_authenticated_keepalive_returns_no_content(): void
    {
        $response = $this->actingAs($this->user())->postJson('/console/session/keepalive');

        $response->assertNoContent();
    }

    public function test_guest_keepalive_is_rejected(): void
    {
        $this->postJson('/console/session/keepalive')->assertUnauthorized();
    }

    public function test_keepalive_rejects_get(): void
    {
        $this->actingAs($this->user())->getJson('/console/session/keepalive')->assertStatus(405);
    }

    public function test_keepalive_is_throttled(): void
    {
        $user = $this->user();

        foreach (range(1, 30) as $_) {
            $this->actingAs($user)->postJson('/console/session/keepalive')->assertNoContent();
        }

        $this->actingAs($user)->postJson('/console/session/keepalive')->assertStatus(429);
    }

    public function test_session_lifetime_is_shared_in_seconds(): void
    {
        config(['session.lifetime' => 45]);

        $this->actingAs($this->user())->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.session_lifetime', 2700));
    }

    public function test_existing_auth_props_are_unchanged(): void
    {
        $this->actingAs($this->user())->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('auth.user')
                ->has('auth.can')
                ->has('auth.effectivePermissions')
                ->has('auth.is_owner')
                ->has('auth.group_id')
                ->has('auth.impersonating'));
    }

    public function test_guest_pages_do_not_expose_session_lifetime(): void
    {
        $this->get('/console/login')
            ->assertInertia(fn ($page) => $page->where('auth.session_lifetime', null));
    }
}
