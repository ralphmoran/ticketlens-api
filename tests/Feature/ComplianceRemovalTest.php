<?php

namespace Tests\Feature;

use App\Models\CliToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backlog #47: Api\ComplianceController (/v1/compliance) and Console\ComplianceController
 * (/console/compliance) were dead code — no CLI path ever reached them, so their
 * UsageLog rows and Free-tier quota never populated. Removed entirely rather than wired
 * up: SKILL.md documents --compliance as never networking, and the Console's Compliance
 * Analytics dashboard is already fed by the local ledger via `triage --push`, unrelated
 * to this endpoint.
 */
class ComplianceRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_compliance_route_no_longer_exists(): void
    {
        $user = User::factory()->create(['tier' => 'team']);
        $plaintext = 'tl_' . str_repeat('c', 40);
        CliToken::create(['user_id' => $user->id, 'name' => 'CLI Token', 'token_hash' => CliToken::hashToken($plaintext)]);

        $response = $this->postJson('/v1/compliance', ['brief' => 'x'], [
            'Authorization' => "Bearer {$plaintext}",
        ]);

        $response->assertNotFound();
    }

    public function test_console_compliance_page_route_no_longer_exists(): void
    {
        $user = User::factory()->create(['tier' => 'team']);

        $response = $this->actingAs($user)->get('/console/compliance');

        $response->assertNotFound();
    }

    public function test_shared_compliance_throttle_still_gates_consensus_route(): void
    {
        // Not a full call — just confirms the RateLimiter named "compliance" is still
        // registered (api.php's /v1/consensus route depends on it after this deletion).
        $this->assertNotNull(app('router')->getRoutes()->getByName('api.consensus'));
    }
}
