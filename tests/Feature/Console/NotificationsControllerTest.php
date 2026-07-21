<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/notifications')->assertRedirect('/console/login');
    }

    public function test_authenticated_user_gets_the_pending_payload_shape(): void
    {
        $user = User::factory()->create(['tier' => 'free']);

        $this->actingAs($user)->getJson('/console/notifications')
            ->assertOk()
            ->assertJsonStructure([
                'count',
                'categories' => [
                    'recall', 'license',
                    'invites' => ['available', 'comingSoon'],
                    'workflowFailures' => ['available', 'comingSoon'],
                ],
            ]);
    }
}
