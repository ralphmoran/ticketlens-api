<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SchedulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/schedules');

        $response->assertRedirect('/console/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        // Free tier (64) has no Schedules bit (1)
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->getJson('/console/schedules');

        $response->assertStatus(403);
    }

    public function test_pro_user_sees_schedules_with_no_license(): void
    {
        // Pro = 71 (64|1|2|4): has Schedules bit
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->get('/console/schedules');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Schedules')
            ->has('schedules')
            ->has('hasLicense')
            ->has('timezones')
        );
    }
}
