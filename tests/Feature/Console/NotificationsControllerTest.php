<?php

namespace Tests\Feature\Console;

use App\Models\Group;
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
                    'recall', 'license', 'invites',
                    'workflowFailures' => ['available', 'comingSoon'],
                ],
            ]);
    }

    public function test_manager_gets_structured_invites_category(): void
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => 'T', 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);

        $this->actingAs($manager)->getJson('/console/notifications')
            ->assertOk()
            ->assertJsonStructure([
                'categories' => [
                    'invites' => ['available', 'count', 'items'],
                ],
            ]);
    }
}
