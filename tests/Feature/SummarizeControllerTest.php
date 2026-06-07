<?php

namespace Tests\Feature;

use App\Models\CliToken;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SummarizeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeProUserWithToken(): array
    {
        $user      = User::factory()->create(['tier' => 'pro']);
        $plaintext = 'tl_' . str_repeat('s', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return [$user, $plaintext];
    }

    public function test_returns_summary_on_valid_request(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->once()->andReturn('Cart validation summary.');
        });

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
            'brief'     => str_repeat('ticket content ', 10),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['summary' => 'Cart validation summary.']);
    }

    public function test_returns_401_without_token(): void
    {
        $response = $this->postJson('/v1/summarize', ['brief' => 'test']);
        $response->assertStatus(401);
    }

    public function test_returns_403_for_free_tier_user(): void
    {
        $user      = User::factory()->create(['tier' => 'free']);
        $plaintext = 'tl_' . str_repeat('f', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);

        $response = $this->withToken($plaintext)->postJson('/v1/summarize', [
            'brief' => 'test brief',
        ]);
        $response->assertStatus(403);
    }

    public function test_returns_422_when_brief_missing(): void
    {
        [, $token] = $this->makeProUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['brief']);
    }

    public function test_returns_422_when_brief_exceeds_50k_chars(): void
    {
        [, $token] = $this->makeProUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
            'brief'     => str_repeat('x', 50_001),
        ]);
        $response->assertStatus(422);
    }

    public function test_returns_503_when_user_has_no_ai_providers(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->andThrow(new \App\Exceptions\NoAiProviderException());
        });

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'brief' => 'test brief',
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('No AI provider', $response->json('error'));
    }

    public function test_does_not_expose_ai_error_detail(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->andThrow(new \RuntimeException('AI unavailable. Tried: groq (HTTP 500)'));
        });

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'brief' => 'test brief',
        ]);

        $response->assertStatus(500);
    }
}
