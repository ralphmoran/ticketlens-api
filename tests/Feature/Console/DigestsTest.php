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
        $response = $this->get('/console/digests');

        $response->assertRedirect('/console/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        // Free tier (64) has no Digests bit (2)
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->getJson('/console/digests');

        $response->assertStatus(403);
    }

    public function test_pro_user_sees_digests_page(): void
    {
        // Pro = 71 (64|1|2|4): has Digests bit
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->get('/console/digests');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Digests')
            ->has('digests')
        );
    }
}
