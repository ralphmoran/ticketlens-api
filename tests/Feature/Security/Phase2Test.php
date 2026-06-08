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
 * Security Audit Phase 2 — HIGH findings.
 * RED phase: these tests must FAIL against unmodified code.
 */
class Phase2Test extends TestCase
{
    use RefreshDatabase;

    // ── H1: XSS — json_encode must use JSON_HEX_TAG flags ───────────────────

    public function test_oauth_popup_escapes_script_injection_in_integration_field(): void
    {
        // If integration param contains </script>, it must be HTML-entity encoded
        // so it cannot break out of the <script> block.
        $response = $this->get('/console/oauth-close?integration=slack%3C%2Fscript%3E&status=success');
        $response->assertStatus(200);
        // Must NOT contain raw </script> inside the JS payload
        $this->assertStringNotContainsString('</script>', $this->extractScriptPayload($response->content()));
    }

    public function test_oauth_popup_escapes_script_injection_in_message_field(): void
    {
        $response = $this->get('/console/oauth-close?integration=slack&status=error&message=%3C%2Fscript%3Ealert(1)');
        $response->assertStatus(200);
        $this->assertStringNotContainsString('</script>', $this->extractScriptPayload($response->content()));
    }

    // ── H2: Open redirect — safePopupOrigin must only allow app URL ──────────

    public function test_slack_callback_error_does_not_redirect_to_arbitrary_https_origin(): void
    {
        // An attacker-controlled origin must not be accepted as a redirect target
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        // Forge a state with popup=true and popup_origin pointing to evil.com
        $state = $this->forgePopupState($group->id, $manager->id, 'https://evil.com');

        $response = $this->get('/console/slack/callback?error=access_denied&state=' . urlencode($state));

        // Must NOT redirect to evil.com
        $location = $response->headers->get('Location', '');
        $this->assertStringNotContainsString('evil.com', $location);
    }

    public function test_slack_callback_accepts_app_origin_for_popup(): void
    {
        $appUrl = rtrim(config('app.url'), '/');

        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        $state = $this->forgePopupState($group->id, $manager->id, $appUrl);

        $response = $this->get('/console/slack/callback?error=access_denied&state=' . urlencode($state));

        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString($appUrl, $location);
    }

    // ── H5: Timing — new tokens store prefix; lookup uses hash_equals ────────

    public function test_new_cli_token_stores_prefix(): void
    {
        $user      = User::factory()->create();
        $plaintext = 'tl_' . str_repeat('b', 40);

        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI',
            'token_hash' => CliToken::hashToken($plaintext),
            'token_prefix' => substr($plaintext, 0, 8),
        ]);

        $token = CliToken::first();
        $this->assertSame(substr($plaintext, 0, 8), $token->token_prefix);
    }

    public function test_find_by_plaintext_uses_prefix_lookup(): void
    {
        $user      = User::factory()->create();
        $plaintext = 'tl_' . str_repeat('c', 40);

        CliToken::create([
            'user_id'      => $user->id,
            'name'         => 'CLI',
            'token_hash'   => CliToken::hashToken($plaintext),
            'token_prefix' => substr($plaintext, 0, 8),
        ]);

        $found = CliToken::findByPlaintext($plaintext);
        $this->assertNotNull($found);
    }

    public function test_wrong_token_with_correct_prefix_is_rejected(): void
    {
        $user      = User::factory()->create(['tier' => 'pro', 'permissions' => 3]);
        $plaintext = 'tl_' . str_repeat('d', 40);
        $wrong     = 'tl_' . str_repeat('d', 7) . str_repeat('X', 33); // same prefix, different body

        CliToken::create([
            'user_id'      => $user->id,
            'name'         => 'CLI',
            'token_hash'   => CliToken::hashToken($plaintext),
            'token_prefix' => substr($plaintext, 0, 8),
        ]);

        // Same prefix, wrong full token — must be rejected (hash_equals catches it)
        $this->withToken($wrong)
            ->getJson('/v1/ai-providers')
            ->assertStatus(401);
    }

    // ── H6: SESSION_SECURE_COOKIE defaults to true in production ─────────────

    public function test_session_secure_defaults_false_in_non_production(): void
    {
        // In test env (APP_ENV=testing), SESSION_SECURE_COOKIE is unset.
        // The default must resolve to false (not null) so local dev works correctly.
        $this->assertFalse(config('session.secure'));
    }

    // ── H7: route constraint blocks unknown types; controller is defense-in-depth

    public function test_route_constraint_blocks_unknown_alert_type(): void
    {
        // Route `where('alertType', 'needs-response|aging|compliance-gap')` fires
        // before the controller — unknown types return 404, not 422.
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->postJson('/console/admin/alerts/injected-type/test')
            ->assertStatus(404);
    }

    public function test_test_rule_integration_is_bound_to_rule_group(): void
    {
        // testRule() must fetch the Slack integration via $rule->group_id,
        // not via resolveGroup() — prevents implicit coupling if auth is relaxed.
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;
        $integration = $this->makeSlack($group);

        $rule = \App\Models\CustomAlertRule::create([
            'group_id'     => $group->id,
            'alert_type'   => 'aging',
            'target_id'    => 'U123',
            'target_label' => '@user',
            'threshold'    => 24,
            'enabled'      => true,
        ]);

        $this->mock(SlackService::class)
            ->shouldReceive('postDm')
            ->once()
            ->with($integration->bot_token, 'U123', \Mockery::type('string'));

        $this->actingAs($manager)
            ->postJson("/console/admin/alerts/rules/{$rule->id}/test")
            ->assertOk();
    }

    // ── H8: /v1/health must not expose version ───────────────────────────────

    public function test_health_does_not_expose_version(): void
    {
        $response = $this->getJson('/v1/health')->assertOk();
        $this->assertArrayNotHasKey('version', $response->json());
    }

    public function test_health_response_contains_only_status(): void
    {
        $response = $this->getJson('/v1/health')->assertOk();
        $this->assertSame(['status' => 'ok'], $response->json());
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

    /** Forge an encrypted state blob that matches the real SlackService::buildAuthUrl() format. */
    private function forgePopupState(int $groupId, int $userId, string $popupOrigin): string
    {
        $state = [
            'group_id'     => $groupId,
            'user_id'      => $userId,
            'is_owner'     => false,
            'popup'        => true,
            'popup_origin' => $popupOrigin,
            'nonce'        => bin2hex(random_bytes(16)),
        ];
        return encrypt(json_encode($state));
    }

    /** Extract the JS payload block from the oauth-popup blade response. */
    private function extractScriptPayload(string $html): string
    {
        if (preg_match('/<script>(.*?)<\/script>/s', $html, $m)) {
            return $m[1];
        }
        return $html;
    }
}
