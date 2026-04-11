<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/team');

        $response->assertRedirect('/console/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        // Pro (71) has no MultiAccount bit (32)
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->getJson('/console/team');

        $response->assertStatus(403);
    }

    public function test_team_user_sees_team_page(): void
    {
        // Team = 127 (71|8|16|32): has MultiAccount bit
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $response = $this->actingAs($user)->get('/console/team');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Team')
            ->has('groups')
        );
    }
}
