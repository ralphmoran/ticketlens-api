<?php

namespace Tests\Feature\Api;

use App\Models\CliToken;
use App\Models\User;
use App\Models\UserAiProvider;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUserWithToken(): array
    {
        $user      = User::factory()->create();
        $plaintext = 'tl_' . str_repeat('b', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return [$user, $plaintext];
    }

    // ── GET /v1/ai-providers ──────────────────────────────────────────────────

    public function test_index_returns_empty_list_for_new_user(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)
            ->getJson('/v1/ai-providers')
            ->assertStatus(200)
            ->assertJson(['providers' => []]);
    }

    public function test_index_returns_providers_with_masked_keys(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        UserAiProvider::factory()->for($user)->create([
            'provider' => 'groq',
            'api_key'  => 'gsk_test_key_12345678',
        ]);

        $response = $this->withToken($token)->getJson('/v1/ai-providers');

        $response->assertStatus(200);
        $provider = $response->json('providers.0');
        $this->assertStringContainsString('***', $provider['masked_key']);
        $this->assertArrayNotHasKey('api_key', $provider);
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/v1/ai-providers')->assertStatus(401);
    }

    // ── POST /v1/ai-providers ─────────────────────────────────────────────────

    public function test_store_creates_provider(): void
    {
        [, $token] = $this->makeUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/ai-providers', [
            'provider' => 'groq',
            'api_key'  => 'gsk_test_key_12345678_abcdef',
        ]);

        $response->assertStatus(201);
        $this->assertSame('groq', $response->json('provider'));
        $this->assertStringContainsString('***', $response->json('masked_key'));
    }

    public function test_store_rejects_unknown_provider(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/ai-providers', [
            'provider' => 'cohere',
            'api_key'  => 'sk_test',
        ])->assertStatus(422);
    }

    public function test_store_rejects_short_api_key(): void
    {
        [, $token] = $this->makeUserWithToken();

        $this->withToken($token)->postJson('/v1/ai-providers', [
            'provider' => 'groq',
            'api_key'  => 'short',
        ])->assertStatus(422);
    }

    public function test_user_a_cannot_see_user_b_providers(): void
    {
        [$userA, $tokenA] = $this->makeUserWithToken();
        $userB = User::factory()->create();
        UserAiProvider::factory()->for($userB)->create(['provider' => 'groq']);

        $response = $this->withToken($tokenA)->getJson('/v1/ai-providers');

        $response->assertStatus(200);
        $this->assertEmpty($response->json('providers'));
    }

    // ── PUT /v1/ai-providers/{id} ─────────────────────────────────────────────

    public function test_update_changes_priority_and_timeout(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        $provider = UserAiProvider::factory()->for($user)->create(['provider' => 'groq']);

        $this->withToken($token)->putJson("/v1/ai-providers/{$provider->id}", [
            'priority'        => 2,
            'timeout_seconds' => 10,
        ])->assertStatus(200)
          ->assertJson(['priority' => 2, 'timeout_seconds' => 10]);
    }

    public function test_user_a_cannot_update_user_b_provider(): void
    {
        [, $tokenA] = $this->makeUserWithToken();
        $userB    = User::factory()->create();
        $provider = UserAiProvider::factory()->for($userB)->create(['provider' => 'groq']);

        $this->withToken($tokenA)->putJson("/v1/ai-providers/{$provider->id}", [
            'priority' => 99,
        ])->assertStatus(404);
    }

    // ── DELETE /v1/ai-providers/{id} ──────────────────────────────────────────

    public function test_destroy_removes_provider(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        $provider = UserAiProvider::factory()->for($user)->create(['provider' => 'groq']);

        $this->withToken($token)->deleteJson("/v1/ai-providers/{$provider->id}")
            ->assertStatus(200)
            ->assertJson(['deleted' => true]);

        $this->assertDatabaseMissing('user_ai_providers', ['id' => $provider->id]);
    }

    public function test_user_a_cannot_delete_user_b_provider(): void
    {
        [, $tokenA] = $this->makeUserWithToken();
        $userB    = User::factory()->create();
        $provider = UserAiProvider::factory()->for($userB)->create(['provider' => 'groq']);

        $this->withToken($tokenA)->deleteJson("/v1/ai-providers/{$provider->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('user_ai_providers', ['id' => $provider->id]);
    }

    // ── POST /v1/ai-providers/{id}/test ──────────────────────────────────────

    public function test_test_endpoint_returns_ok_on_success(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        $provider = UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);

        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('testProvider')->once()->andReturn('OK');
        });

        $this->withToken($token)->postJson("/v1/ai-providers/{$provider->id}/test")
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_test_endpoint_returns_error_when_provider_disabled(): void
    {
        [$user, $token] = $this->makeUserWithToken();
        $provider = UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => false]);

        $this->withToken($token)->postJson("/v1/ai-providers/{$provider->id}/test")
            ->assertStatus(422)
            ->assertJson(['error' => 'Provider is disabled.']);
    }
}
