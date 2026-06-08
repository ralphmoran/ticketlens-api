<?php

namespace Tests\Feature\Security;

use App\Models\CliToken;
use App\Models\Group;
use App\Models\License;
use App\Models\SlackIntegration;
use App\Models\User;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lock tests for Security Audit Phase 2.
 * These characterize EXISTING behaviour that must be preserved through all fixes.
 */
class Phase2LockTest extends TestCase
{
    use RefreshDatabase;

    // ── H8: /v1/health — status 200 and status=ok are invariants ────────────

    public function test_health_returns_200(): void
    {
        $this->getJson('/v1/health')->assertStatus(200);
    }

    public function test_health_returns_status_ok(): void
    {
        $this->getJson('/v1/health')->assertJson(['status' => 'ok']);
    }

    public function test_health_requires_no_authentication(): void
    {
        $this->getJson('/v1/health')->assertStatus(200);
    }

    // ── H1/H2: OAuth popup — happy path callback renders blade ───────────────

    public function test_popup_oauth_close_page_renders_on_success(): void
    {
        $this->get('/console/oauth-close?integration=slack&status=success')
            ->assertStatus(200);
    }

    public function test_slack_redirect_rejects_missing_group_id(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)
            ->get('/console/slack/redirect')
            ->assertStatus(422);
    }

    // ── H5: CLI token auth — valid token still authenticates ─────────────────

    public function test_valid_cli_token_authenticates(): void
    {
        $user      = User::factory()->create(['tier' => 'pro', 'permissions' => 3]);
        $plaintext = 'tl_' . str_repeat('a', 40);

        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);

        $this->withToken($plaintext)
            ->getJson('/v1/ai-providers')
            ->assertStatus(200);
    }

    public function test_invalid_cli_token_is_rejected(): void
    {
        $this->withToken('tl_' . str_repeat('z', 40))
            ->getJson('/v1/ai-providers')
            ->assertStatus(401);
    }

    // ── H7: testAlert — known alert types succeed ────────────────────────────

    public function test_test_alert_with_known_type_sends_slack_message(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);

        $this->mock(SlackService::class)
            ->shouldReceive('postMessage')
            ->once();

        $this->actingAs($manager)
            ->postJson('/console/admin/alerts/needs-response/test')
            ->assertOk();
    }

    public function test_test_alert_with_aging_type_succeeds(): void
    {
        $manager = $this->makeManager();
        $this->makeSlack($manager->ownedGroup);

        $this->mock(SlackService::class)->shouldReceive('postMessage')->once();

        $this->actingAs($manager)
            ->postJson('/console/admin/alerts/aging/test')
            ->assertOk();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        License::create([
            'user_id'        => $manager->id,
            'lemon_key_hash' => hash('sha256', 'mgr-' . $manager->id),
            'status'         => 'active',
            'tier'           => 'team',
            'seats'          => 5,
        ]);
        return $manager;
    }

    private function makeSlack(Group $group): SlackIntegration
    {
        return SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $group->owner_id,
            'workspace_id'   => 'W123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
            'channel_id'     => 'C001',
            'channel_name'   => 'triages',
        ]);
    }
}
