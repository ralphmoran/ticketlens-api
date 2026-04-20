<?php

namespace Tests\Feature\Owner;

use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    public function test_owner_can_view_revenue(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->get('/console/owner/revenue');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Owner/Revenue'));
    }

    public function test_non_owner_cannot_view_revenue(): void
    {
        $user = User::factory()->create(['tier' => 'enterprise', 'permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/revenue');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_revenue_computes_mrr_from_active_paid_licenses(): void
    {
        $owner = $this->makeOwner();

        $proUser   = User::factory()->create(['tier' => 'pro']);
        $teamUser  = User::factory()->create(['tier' => 'team']);
        $freeUser  = User::factory()->create(['tier' => 'free']);

        License::create(['user_id' => $proUser->id,  'lemon_key_hash' => str_repeat('a', 64), 'status' => 'active', 'tier' => 'pro']);
        License::create(['user_id' => $teamUser->id, 'lemon_key_hash' => str_repeat('b', 64), 'status' => 'active', 'tier' => 'team']);
        License::create(['user_id' => $freeUser->id, 'lemon_key_hash' => str_repeat('c', 64), 'status' => 'active', 'tier' => 'free']);

        $response = $this->actingAs($owner)->get('/console/owner/revenue');

        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Revenue')
            ->where('mrr', 23)   // pro(8) + team(15) + free(0)
            ->where('total_active', 3)
        );
    }

    public function test_revenue_excludes_expired_licenses(): void
    {
        $owner = $this->makeOwner();
        $user  = User::factory()->create(['tier' => 'pro']);

        License::create([
            'user_id'        => $user->id,
            'lemon_key_hash' => str_repeat('d', 64),
            'status'         => 'active',
            'tier'           => 'pro',
            'expires_at'     => now()->subDay(),
        ]);

        $response = $this->actingAs($owner)->get('/console/owner/revenue');

        $response->assertInertia(fn ($page) => $page
            ->where('mrr', 0)
            ->where('total_active', 0)
        );
    }
}
