<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/compliance');

        $response->assertRedirect('/console/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        // Pro (71) has no Compliance bit (8)
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->getJson('/console/compliance');

        $response->assertStatus(403);
    }

    public function test_team_user_sees_compliance_unlimited(): void
    {
        // Team = 127 (71|8|16|32): has Compliance bit, tier != 'free' → monthlyLimit = null
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);

        $response = $this->actingAs($user)->get('/console/compliance');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Compliance')
            ->has('checks')
            ->where('monthlyLimit', null)
        );
    }

    public function test_free_user_with_compliance_bit_sees_3_limit(): void
    {
        // Free (64) | Compliance (8) = 72: has the bit, but tier='free' → monthlyLimit = 3
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 72]);

        $response = $this->actingAs($user)->get('/console/compliance');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Compliance')
            ->has('checks')
            ->where('monthlyLimit', 3)
        );
    }
}
