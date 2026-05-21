<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DigestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/digest-history');

        $response->assertRedirect('/console/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        // Free tier (64) has no Digests bit (2)
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->getJson('/console/digest-history');

        $response->assertStatus(403);
    }

    public function test_pro_user_sees_digests_page(): void
    {
        // Pro = 71 (64|1|2|4): has Digests bit
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->get('/console/digest-history');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Digests')
            ->has('digests')
        );
    }

    public function test_owner_can_access_digests_page(): void
    {
        $owner = User::factory()->create([
            'tier'        => 'owner',
            'permissions' => 0,
            'is_owner'    => true,
        ]);

        $response = $this->actingAs($owner)->get('/console/digest-history');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Digests')
            ->has('digests')
        );
    }
}
