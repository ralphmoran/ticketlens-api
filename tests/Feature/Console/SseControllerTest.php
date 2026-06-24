<?php

namespace Tests\Feature\Console;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SseControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePro(): User
    {
        $user  = User::factory()->create(['tier' => 'pro', 'permissions' => 255]);
        $group = Group::create(['name' => "Pro {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return $user;
    }

    private function makeTeam(): User
    {
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return $user;
    }

    private function makeOwner(): User
    {
        $user  = User::factory()->create(['tier' => 'owner', 'is_owner' => true, 'permissions' => 0]);
        $group = Group::create(['name' => "Owner {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return $user;
    }

    public function test_guest_is_redirected_from_events_stream(): void
    {
        $this->get('/console/events')->assertRedirect('/console/login');
    }

    public function test_free_user_gets_403_from_events_stream(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 0]);

        $this->actingAs($user)->get('/console/events')->assertForbidden();
    }

    public function test_pro_user_without_group_gets_403_from_events_stream(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 255]);

        $this->actingAs($user)->get('/console/events')->assertForbidden();
    }

    public function test_pro_user_gets_200_with_sse_headers(): void
    {
        $user = $this->makePro();

        $response = $this->actingAs($user)->get('/console/events');

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        $response->assertHeader('Cache-Control', 'no-cache, private');
    }

    public function test_team_user_gets_200_with_sse_headers(): void
    {
        $user = $this->makeTeam();

        $response = $this->actingAs($user)->get('/console/events');

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        $response->assertHeader('Cache-Control', 'no-cache, private');
    }

    public function test_owner_gets_200_with_sse_headers(): void
    {
        $user = $this->makeOwner();

        $response = $this->actingAs($user)->get('/console/events');

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        $response->assertHeader('Cache-Control', 'no-cache, private');
    }
}
