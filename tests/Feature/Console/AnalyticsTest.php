<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/analytics');

        $response->assertRedirect('/console/login');
    }

    public function test_free_tier_user_sees_analytics_with_null_stats(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->get('/console/analytics');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Analytics')
            ->where('tier', 'free')
            ->where('stats', null)
            ->has('daily')
        );
    }

    public function test_pro_tier_user_sees_analytics_with_stats(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->get('/console/analytics');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Analytics')
            ->where('tier', 'pro')
            ->has('stats')
            ->has('stats.totalTokens')
            ->has('stats.totalCalls')
            ->has('stats.byAction')
            ->has('daily')
        );
    }
}
