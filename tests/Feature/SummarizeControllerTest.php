<?php

namespace Tests\Feature;

use App\Models\CliToken;
use App\Models\User;
use App\Models\UserAiProvider;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        [$user, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarizeWithUsage')->once()
                ->andReturn(['text' => 'Cart validation summary.', 'tokens' => 150, 'provider' => 'anthropic']);
        });

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
            'brief'     => str_repeat('ticket content ', 10),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['summary' => 'Cart validation summary.']);
    }

    // ── Route-level integration — real AiService, no mock, proves the full
    //    controller+service+response-shape path, not just the controller's
    //    own contract with a stubbed AiService. ──────────────────────────────

    public function test_full_stack_records_one_row_from_the_provider_that_actually_succeeded(): void
    {
        [$user, $token] = $this->makeProUserWithToken();
        UserAiProvider::factory()->for($user)->create(['provider' => 'anthropic', 'enabled' => true, 'priority' => 1]);
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true, 'priority' => 2]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([], 401),
            'api.groq.com/*'      => Http::response([
                'choices' => [['message' => ['content' => 'Real summary.']]],
                'usage'   => ['total_tokens' => 55],
            ], 200),
        ]);

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
            'brief'     => 'ticket content',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['summary' => 'Real summary.']);
        $this->assertDatabaseCount('usage_logs', 1);
        $this->assertDatabaseHas('usage_logs', [
            'user_id'     => $user->id,
            'action'      => 'summarize',
            'ticket_key'  => 'PROJ-123',
            'tokens_used' => 55,
            'metadata'    => null,
        ]);
    }

    // ── ADVERSARIAL axis: ordering — repeated calls never merge or overwrite ──

    public function test_two_consecutive_calls_each_produce_their_own_row(): void
    {
        [$user, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarizeWithUsage')->twice()->andReturn(
                ['text' => 'First.', 'tokens' => 10, 'provider' => 'groq'],
                ['text' => 'Second.', 'tokens' => 20, 'provider' => 'groq'],
            );
        });

        $this->withToken($token)->postJson('/v1/summarize', ['ticketKey' => 'PROJ-1', 'brief' => 'first']);
        $this->withToken($token)->postJson('/v1/summarize', ['ticketKey' => 'PROJ-2', 'brief' => 'second']);

        $this->assertDatabaseCount('usage_logs', 2);
        $this->assertDatabaseHas('usage_logs', ['ticket_key' => 'PROJ-1', 'tokens_used' => 10]);
        $this->assertDatabaseHas('usage_logs', ['ticket_key' => 'PROJ-2', 'tokens_used' => 20]);
    }

    // ── UsageLog recording — real token counts, only on success ────────────────

    public function test_records_usage_log_with_real_token_count_on_success(): void
    {
        [$user, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarizeWithUsage')->once()
                ->andReturn(['text' => 'Summary.', 'tokens' => 213, 'provider' => 'groq']);
        });

        $this->withToken($token)->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
            'brief'     => 'ticket content',
        ]);

        $this->assertDatabaseHas('usage_logs', [
            'user_id'     => $user->id,
            'action'      => 'summarize',
            'ticket_key'  => 'PROJ-123',
            'tokens_used' => 213,
            'metadata'    => null,
        ]);
    }

    public function test_records_usage_log_with_null_ticket_key_when_omitted(): void
    {
        [$user, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarizeWithUsage')->once()
                ->andReturn(['text' => 'Summary.', 'tokens' => 40, 'provider' => 'groq']);
        });

        $this->withToken($token)->postJson('/v1/summarize', ['brief' => 'ticket content']);

        $this->assertDatabaseHas('usage_logs', [
            'user_id'     => $user->id,
            'action'      => 'summarize',
            'ticket_key'  => null,
            'tokens_used' => 40,
        ]);
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
            $mock->shouldReceive('summarizeWithUsage')->andThrow(new \App\Exceptions\NoAiProviderException());
        });

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'brief' => 'test brief',
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('No AI provider', $response->json('error'));
        $this->assertDatabaseCount('usage_logs', 0);
    }

    public function test_does_not_expose_ai_error_detail(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarizeWithUsage')->andThrow(new \RuntimeException('AI unavailable. Tried: groq (HTTP 500)'));
        });

        $response = $this->withToken($token)->postJson('/v1/summarize', [
            'brief' => 'test brief',
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseCount('usage_logs', 0);
    }
}
