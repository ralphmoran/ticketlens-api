<?php

namespace Tests\Feature\Api\Triage;

use App\Enums\Permission;
use App\Models\CliToken;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUserWithToken(string $tier = 'team'): array
    {
        $permissions = match ($tier) {
            'team', 'enterprise' => Permission::team(),
            'pro'                => Permission::pro(),
            default              => Permission::free(),
        };
        $user      = User::factory()->create(['tier' => $tier, 'permissions' => $permissions]);
        $plaintext = 'tl_' . str_repeat('b', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return [$user, $plaintext];
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'profile'     => 'production',
            'captured_at' => '2026-05-20T10:00:00Z',
            'tickets'     => [
                [
                    'key'                 => 'PROJ-123',
                    'summary'             => 'Fix login page',
                    'status'              => 'Code Review',
                    'assignee'            => 'John Doe',
                    'attention_score'     => 8.5,
                    'flags'               => ['needs-response'],
                    'compliance_coverage' => null,
                    'compliance_status'   => 'unknown',
                    'url'                 => 'https://jira.example.com/browse/PROJ-123',
                    'last_updated'        => '2026-05-10T09:00:00Z',
                ],
            ],
        ], $overrides);
    }

    // ── LOCK: permission enum invariant ───────────────────────────────────────

    public function test_lock_attention_queue_permission_value_is_512(): void
    {
        $this->assertSame(512, Permission::AttentionQueue->value);
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_missing_token_returns_401(): void
    {
        $this->postJson('/v1/triage/share', $this->validPayload())->assertStatus(401);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('bad-token')->postJson('/v1/triage/share', $this->validPayload())->assertStatus(401);
    }

    // ── Permissions ───────────────────────────────────────────────────────────

    public function test_free_tier_user_returns_403_and_no_snapshot(): void
    {
        [, $token] = $this->makeUserWithToken('free');

        $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload())->assertStatus(403);
        $this->assertSame(0, TriageSnapshot::count());
    }

    public function test_pro_tier_user_returns_403(): void
    {
        // Pro tier lacks AttentionQueue (512) — share is a Team-only feature
        [, $token] = $this->makeUserWithToken('pro');

        $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload())->assertStatus(403);
        $this->assertSame(0, TriageSnapshot::count());
    }

    // ── Share behaviour ───────────────────────────────────────────────────────

    public function test_valid_share_stores_snapshot_and_returns_url(): void
    {
        [, $token] = $this->makeUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload());

        $response->assertStatus(200);
        $response->assertJsonStructure(['url', 'expires_at']);
        $this->assertStringContainsString('/s/', $response->json('url'));
    }

    public function test_share_url_contains_the_stored_token(): void
    {
        [, $token] = $this->makeUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload());

        $snapshot = TriageSnapshot::first();
        $this->assertNotNull($snapshot->share_token);
        $this->assertStringEndsWith($snapshot->share_token, $response->json('url'));
    }

    public function test_share_expires_in_approximately_24h(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload());

        $diff = TriageSnapshot::first()->share_expires_at->diffInMinutes(now()->addHours(24), absolute: true);
        $this->assertLessThan(2, $diff);
    }

    public function test_second_share_renews_token_and_upserts_snapshot(): void
    {
        [, $token] = $this->makeUserWithToken();
        $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload());
        $firstToken = TriageSnapshot::first()->share_token;

        $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload());

        $this->assertSame(1, TriageSnapshot::count());
        $this->assertNotEquals($firstToken, TriageSnapshot::first()->share_token);
    }

    public function test_snapshot_linked_to_user(): void
    {
        [$user, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload());

        $this->assertSame($user->id, TriageSnapshot::first()->user_id);
    }

    public function test_missing_profile_returns_422(): void
    {
        [, $token] = $this->makeUserWithToken();
        $payload   = $this->validPayload();
        unset($payload['profile']);

        $this->withToken($token)->postJson('/v1/triage/share', $payload)->assertStatus(422);
    }

    public function test_push_and_share_coexist_as_single_upserted_row(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/triage/push', $this->validPayload());
        $this->withToken($token)->postJson('/v1/triage/share', $this->validPayload());

        // Same user_id + profile → still one row (upsert), but now has share_token
        $this->assertSame(1, TriageSnapshot::count());
        $this->assertNotNull(TriageSnapshot::first()->share_token);
    }

    // ── Public share page ────────────────────────────────────────────────────

    public function test_public_page_renders_for_valid_token(): void
    {
        TriageSnapshot::create([
            'profile'          => 'production',
            'tickets'          => [['key' => 'PROJ-1', 'summary' => 'Test ticket', 'flags' => []]],
            'ticket_count'     => 1,
            'captured_at'      => now(),
            'share_token'      => 'test-token-abc',
            'share_expires_at' => now()->addHours(24),
        ]);

        $this->get('/s/test-token-abc')
            ->assertStatus(200)
            ->assertSee('PROJ-1')
            ->assertSee('Test ticket');
    }

    public function test_public_page_returns_404_for_expired_token(): void
    {
        TriageSnapshot::create([
            'profile'          => 'production',
            'tickets'          => [],
            'ticket_count'     => 0,
            'captured_at'      => now(),
            'share_token'      => 'expired-token',
            'share_expires_at' => now()->subHours(1),
        ]);

        $this->get('/s/expired-token')->assertStatus(404);
    }

    public function test_public_page_returns_404_for_unknown_token(): void
    {
        $this->get('/s/nonexistent-token-xyz')->assertStatus(404);
    }

    public function test_public_page_requires_no_authentication(): void
    {
        TriageSnapshot::create([
            'profile'          => 'dev',
            'tickets'          => [],
            'ticket_count'     => 0,
            'captured_at'      => now(),
            'share_token'      => 'public-token',
            'share_expires_at' => now()->addHours(24),
        ]);

        $this->get('/s/public-token')->assertStatus(200);
    }
}
