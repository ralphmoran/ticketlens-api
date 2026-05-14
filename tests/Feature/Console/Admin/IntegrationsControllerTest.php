<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\License;
use App\Models\SlackIntegration;
use App\Models\User;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationsControllerTest extends TestCase
{
    use RefreshDatabase;

    // --- Regression lock tests ---

    public function test_existing_admin_members_route_still_accessible_to_manager(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)->get('/console/admin/members')->assertOk();
    }

    public function test_existing_admin_seats_route_still_accessible_to_manager(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)->get('/console/admin/seats')->assertOk();
    }

    // --- Access control ---

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/admin/integrations')->assertRedirect('/console/login');
    }

    public function test_plain_team_member_cannot_access_integrations(): void
    {
        $member = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $this->actingAs($member)->get('/console/admin/integrations')->assertRedirect('/console/dashboard');
    }

    public function test_manager_can_view_integrations_page(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)->get('/console/admin/integrations')->assertOk();
    }

    public function test_owner_can_view_integrations_page_with_group_id(): void
    {
        $owner  = $this->makeOwner();
        $group  = $this->makeStandaloneGroup();
        $this->actingAs($owner)->get('/console/admin/integrations?group_id=' . $group->id)->assertOk();
    }

    // --- index() returns correct integration state ---

    public function test_index_returns_null_integration_when_not_connected(): void
    {
        $manager = $this->makeManager();

        $response = $this->actingAs($manager)->get('/console/admin/integrations');

        $response->assertInertia(fn ($page) => $page
            ->component('Console/Admin/Integrations')
            ->where('integration', null)
        );
    }

    public function test_index_returns_integration_data_when_connected(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $manager->id,
            'workspace_id'   => 'T123',
            'workspace_name' => 'Acme Corp',
            'bot_token'      => 'xoxb-test',
            'channel_id'     => 'C456',
            'channel_name'   => 'alerts',
        ]);

        $response = $this->actingAs($manager)->get('/console/admin/integrations');

        $response->assertInertia(fn ($page) => $page
            ->where('integration.workspace_name', 'Acme Corp')
            ->where('integration.channel_name', 'alerts')
        );
    }

    // --- disconnect() ---

    public function test_manager_can_disconnect_their_integration(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $manager->id,
            'workspace_id'   => 'T123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
        ]);

        $this->actingAs($manager)
            ->delete('/console/admin/integrations')
            ->assertRedirect();

        $this->assertDatabaseMissing('slack_integrations', ['group_id' => $group->id]);
    }

    public function test_owner_can_disconnect_any_groups_integration(): void
    {
        $owner = $this->makeOwner();
        $group = $this->makeStandaloneGroup();

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $owner->id,
            'workspace_id'   => 'T999',
            'workspace_name' => 'ClientCo',
            'bot_token'      => 'xoxb-client',
        ]);

        $this->actingAs($owner)
            ->delete('/console/admin/integrations?group_id=' . $group->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('slack_integrations', ['group_id' => $group->id]);
    }

    // --- saveChannel() ---

    public function test_manager_can_save_channel(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $manager->id,
            'workspace_id'   => 'T123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
        ]);

        $this->actingAs($manager)
            ->post('/console/admin/integrations/channel', [
                'channel_id'   => 'C001',
                'channel_name' => 'engineering',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('slack_integrations', [
            'group_id'    => $group->id,
            'channel_id'  => 'C001',
            'channel_name' => 'engineering',
        ]);
    }

    // --- channels() mocks Slack API ---

    public function test_index_connect_url_is_local_redirect_not_slack_url(): void
    {
        $manager = $this->makeManager();

        $response = $this->actingAs($manager)->get('/console/admin/integrations');

        $response->assertInertia(fn ($page) => $page
            ->where('connect_url', '/console/slack/redirect?group_id=' . $manager->ownedGroup->id)
        );
    }

    // --- channels() mocks Slack API ---

    public function test_channels_endpoint_returns_channel_list(): void
    {
        $this->mock(SlackService::class, function ($mock) {
            $mock->shouldReceive('fetchChannels')->andReturn([
                ['id' => 'C001', 'name' => 'general', 'is_private' => false],
                ['id' => 'C002', 'name' => 'alerts',  'is_private' => false],
            ]);
        });

        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $manager->id,
            'workspace_id'   => 'T123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
        ]);

        $this->actingAs($manager)
            ->getJson('/console/admin/integrations/channels')
            ->assertOk()
            ->assertJsonCount(2, 'channels')
            ->assertJsonPath('channels.0.name', 'general');
    }

    // --- sendTest() ---

    public function test_manager_can_send_test_message_to_configured_channel(): void
    {
        $this->mock(SlackService::class, function ($mock) {
            $mock->shouldReceive('postMessage')
                ->once()
                ->with('xoxb-test', 'C001', \Mockery::type('string'));
        });

        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $manager->id,
            'workspace_id'   => 'T123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
            'channel_id'     => 'C001',
            'channel_name'   => 'alerts',
        ]);

        $this->actingAs($manager)
            ->postJson('/console/admin/integrations/test')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_send_test_returns_502_when_slack_errors(): void
    {
        $this->mock(SlackService::class, function ($mock) {
            $mock->shouldReceive('postMessage')
                ->once()
                ->andThrow(new \RuntimeException('channel_not_found'));
        });

        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $manager->id,
            'workspace_id'   => 'T123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
            'channel_id'     => 'C001',
            'channel_name'   => 'alerts',
        ]);

        $this->actingAs($manager)
            ->postJson('/console/admin/integrations/test')
            ->assertStatus(502)
            ->assertJsonPath('error', 'channel_not_found');
    }

    public function test_send_test_returns_422_when_no_channel_configured(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $manager->id,
            'workspace_id'   => 'T123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
            'channel_id'     => null,
            'channel_name'   => null,
        ]);

        $this->actingAs($manager)
            ->postJson('/console/admin/integrations/test')
            ->assertStatus(422);
    }

    public function test_plain_member_cannot_send_test_message(): void
    {
        $member = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $this->actingAs($member)
            ->postJson('/console/admin/integrations/test')
            ->assertForbidden();
    }

    public function test_owner_can_send_test_message_for_any_group(): void
    {
        $this->mock(SlackService::class, function ($mock) {
            $mock->shouldReceive('postMessage')
                ->once()
                ->with('xoxb-client', 'C999', \Mockery::type('string'));
        });

        $owner = $this->makeOwner();
        $group = $this->makeStandaloneGroup();

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $owner->id,
            'workspace_id'   => 'T999',
            'workspace_name' => 'ClientCo',
            'bot_token'      => 'xoxb-client',
            'channel_id'     => 'C999',
            'channel_name'   => 'engineering',
        ]);

        $this->actingAs($owner)
            ->postJson('/console/owner/integrations/test?group_id=' . $group->id)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    // --- Helpers ---

    private function makeStandaloneGroup(): Group
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $group = Group::create(['name' => 'Standalone ' . $user->id, 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return $group;
    }

    private function makeManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);

        License::create([
            'user_id'        => $manager->id,
            'lemon_key_hash' => hash('sha256', 'manager-' . $manager->id . uniqid()),
            'status'         => 'active',
            'tier'           => 'team',
            'seats'          => 5,
        ]);

        return $manager;
    }

    private function makeOwner(): User
    {
        return User::factory()->create([
            'tier'        => 'owner',
            'permissions' => 0,
            'is_owner'    => true,
        ]);
    }
}
