<?php

namespace Tests\Feature\Console;

use App\Models\Group;
use App\Models\SlackIntegration;
use App\Models\User;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlackOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // --- redirect() ---

    public function test_redirect_sends_manager_to_slack_auth_url(): void
    {
        $this->mock(SlackService::class, function ($mock) {
            $mock->shouldReceive('buildAuthUrl')
                ->once()
                ->with(1)
                ->andReturn('https://slack.com/oauth/v2/authorize?state=xyz');
        });

        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->actingAs($manager)
            ->get('/console/slack/redirect?group_id=' . $group->id)
            ->assertRedirect('https://slack.com/oauth/v2/authorize?state=xyz');
    }

    public function test_redirect_rejects_manager_requesting_another_groups_id(): void
    {
        $manager    = $this->makeManager();
        $otherUser  = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $otherGroup = Group::create(['name' => 'Other', 'owner_id' => $otherUser->id]);

        $this->actingAs($manager)
            ->get('/console/slack/redirect?group_id=' . $otherGroup->id)
            ->assertForbidden();
    }

    public function test_redirect_rejects_missing_group_id(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->get('/console/slack/redirect')
            ->assertStatus(422);
    }

    public function test_owner_can_redirect_for_any_group(): void
    {
        $this->mock(SlackService::class, function ($mock) {
            $mock->shouldReceive('buildAuthUrl')->andReturn('https://slack.com/auth');
        });

        $owner     = $this->makeOwner();
        $groupUser = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $group     = Group::create(['name' => 'Client Team', 'owner_id' => $groupUser->id]);

        $this->actingAs($owner)
            ->get('/console/slack/redirect?group_id=' . $group->id)
            ->assertRedirect('https://slack.com/auth');
    }

    // --- callback() ---

    public function test_callback_stores_integration_on_success(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $this->mock(SlackService::class, function ($mock) use ($group) {
            $mock->shouldReceive('decodeState')
                ->once()
                ->andReturn(['group_id' => $group->id, 'nonce' => 'abc']);

            $mock->shouldReceive('exchangeCode')
                ->once()
                ->andReturn([
                    'workspace_id'   => 'T123',
                    'workspace_name' => 'Acme Corp',
                    'bot_token'      => 'xoxb-secret',
                ]);
        });

        $this->actingAs($manager)
            ->get('/console/slack/callback?code=authcode&state=encryptedstate')
            ->assertRedirect('/console/admin/integrations');

        $this->assertDatabaseHas('slack_integrations', [
            'group_id'       => $group->id,
            'workspace_id'   => 'T123',
            'workspace_name' => 'Acme Corp',
        ]);

        // Verify bot_token is NOT stored in plain text
        $raw = \DB::table('slack_integrations')->where('group_id', $group->id)->value('bot_token');
        $this->assertNotEquals('xoxb-secret', $raw);
    }

    public function test_callback_redirects_with_error_when_slack_denies(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->get('/console/slack/callback?error=access_denied&state=x')
            ->assertRedirect('/console/admin/integrations');
    }

    public function test_callback_redirects_with_error_on_invalid_state(): void
    {
        $this->mock(SlackService::class, function ($mock) {
            $mock->shouldReceive('decodeState')
                ->once()
                ->andThrow(new \RuntimeException('Invalid OAuth state.'));
        });

        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->get('/console/slack/callback?code=x&state=tampered')
            ->assertRedirect('/console/admin/integrations');
    }

    public function test_callback_updates_existing_integration(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $manager->id,
            'workspace_id'   => 'T_OLD',
            'workspace_name' => 'Old Workspace',
            'bot_token'      => 'xoxb-old',
        ]);

        $this->mock(SlackService::class, function ($mock) use ($group) {
            $mock->shouldReceive('decodeState')->andReturn(['group_id' => $group->id, 'nonce' => 'x']);
            $mock->shouldReceive('exchangeCode')->andReturn([
                'workspace_id'   => 'T_NEW',
                'workspace_name' => 'New Workspace',
                'bot_token'      => 'xoxb-new',
            ]);
        });

        $this->actingAs($manager)
            ->get('/console/slack/callback?code=newcode&state=enc')
            ->assertRedirect();

        $this->assertDatabaseCount('slack_integrations', 1);
        $this->assertDatabaseHas('slack_integrations', ['workspace_name' => 'New Workspace']);
    }

    // --- Helpers ---

    private function makeManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
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
