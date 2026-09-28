<?php

namespace Tests\Feature;

use App\Models\CliToken;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecallAutoCaptureControllerTest extends TestCase
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

    public function test_returns_capture_decision_on_valid_ai_response(): void
    {
        [$user, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn([
                'text'     => '{"decision":"capture","title":"Short title","body":"Short body.","tags":["retry-backoff"]}',
                'tokens'   => 90,
                'provider' => 'groq',
            ]);
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => str_repeat('session content ', 10),
            'ticket_key' => 'PROJ-123',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'decision' => 'capture',
            'title' => 'Short title',
            'body' => 'Short body.',
            'tags' => ['retry-backoff'],
        ]);
    }

    // ── UsageLog recording — real token counts, regardless of capture/skip ────

    public function test_records_usage_log_with_real_token_count_on_capture_decision(): void
    {
        [$user, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn([
                'text'     => '{"decision":"capture","title":"t","body":"b","tags":[]}',
                'tokens'   => 90,
                'provider' => 'groq',
            ]);
        });

        $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'session content',
            'ticket_key'         => 'PROJ-123',
        ]);

        $this->assertDatabaseHas('usage_logs', [
            'user_id'     => $user->id,
            'action'      => 'recall_auto_capture',
            'ticket_key'  => 'PROJ-123',
            'tokens_used' => 90,
            'metadata'    => null,
        ]);
    }

    public function test_records_usage_log_even_when_decision_is_skip(): void
    {
        // Tokens are spent judging the transcript whether or not it results in a capture.
        [$user, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn([
                'text' => '{"decision":"skip"}', 'tokens' => 35, 'provider' => 'groq',
            ]);
        });

        $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'nothing interesting happened',
        ]);

        $this->assertDatabaseHas('usage_logs', [
            'user_id'     => $user->id,
            'action'      => 'recall_auto_capture',
            'tokens_used' => 35,
        ]);
    }

    public function test_returns_skip_decision_on_valid_ai_response(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn(['text' => '{"decision":"skip"}', 'tokens' => 20, 'provider' => 'groq']);
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'nothing interesting happened',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['decision' => 'skip']);
        $response->assertExactJson(['decision' => 'skip']);
    }

    public function test_fails_safe_to_skip_on_malformed_json(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn(['text' => 'not json at all, sorry!', 'tokens' => 15, 'provider' => 'groq']);
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'session content',
        ]);

        $response->assertStatus(200);
        $response->assertExactJson(['decision' => 'skip']);
    }

    public function test_fails_safe_to_skip_on_incomplete_capture_payload(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            // decision=capture but missing title
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn([
                'text' => '{"decision":"capture","body":"Short body.","tags":["x"]}', 'tokens' => 25, 'provider' => 'groq',
            ]);
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'session content',
        ]);

        $response->assertStatus(200);
        $response->assertExactJson(['decision' => 'skip']);
    }

    public function test_fails_safe_to_skip_on_unknown_decision_value(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn(['text' => '{"decision":"maybe"}', 'tokens' => 18, 'provider' => 'groq']);
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'session content',
        ]);

        $response->assertStatus(200);
        $response->assertExactJson(['decision' => 'skip']);
    }

    public function test_returns_401_without_token(): void
    {
        $response = $this->postJson('/v1/recall/auto-capture', ['transcript_excerpt' => 'test']);
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

        $response = $this->withToken($plaintext)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'test',
        ]);
        $response->assertStatus(403);
    }

    public function test_returns_422_when_transcript_excerpt_missing(): void
    {
        [, $token] = $this->makeProUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'ticket_key' => 'PROJ-123',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['transcript_excerpt']);
    }

    public function test_returns_422_when_transcript_excerpt_exceeds_8000_chars(): void
    {
        [, $token] = $this->makeProUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => str_repeat('x', 8001),
        ]);
        $response->assertStatus(422);
    }

    public function test_accepts_transcript_excerpt_at_exactly_8000_chars(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn(['text' => '{"decision":"skip"}', 'tokens' => 22, 'provider' => 'groq']);
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => str_repeat('x', 8000),
        ]);
        $response->assertStatus(200);
    }

    public function test_truncates_an_oversized_ai_returned_title_and_body_to_the_hard_server_side_cap(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $overlong = str_repeat('a', 1000);
        $this->mock(AiService::class, function ($mock) use ($overlong) {
            $mock->shouldReceive('generateTextWithUsage')->once()->andReturn([
                'text'     => json_encode(['decision' => 'capture', 'title' => $overlong, 'body' => $overlong, 'tags' => []]),
                'tokens'   => 300,
                'provider' => 'groq',
            ]);
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'session content',
        ]);

        $response->assertStatus(200);
        $this->assertSame(400, strlen($response->json('title')));
        $this->assertSame(400, strlen($response->json('body')));
    }

    public function test_returns_422_for_invalid_ticket_key_format(): void
    {
        [, $token] = $this->makeProUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'session content',
            'ticket_key' => 'not-a-valid-key',
        ]);
        $response->assertStatus(422);
    }

    public function test_returns_503_when_user_has_no_ai_providers(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateTextWithUsage')->andThrow(new \App\Exceptions\NoAiProviderException());
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'test',
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('No AI provider', $response->json('error'));
        $this->assertDatabaseCount('usage_logs', 0);
    }
}
