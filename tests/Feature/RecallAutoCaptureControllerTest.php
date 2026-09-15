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
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateText')->once()->andReturn(
                '{"decision":"capture","title":"Short title","body":"Short body.","tags":["retry-backoff"]}'
            );
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

    public function test_returns_skip_decision_on_valid_ai_response(): void
    {
        [, $token] = $this->makeProUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('generateText')->once()->andReturn('{"decision":"skip"}');
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
            $mock->shouldReceive('generateText')->once()->andReturn('not json at all, sorry!');
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
            $mock->shouldReceive('generateText')->once()->andReturn(
                '{"decision":"capture","body":"Short body.","tags":["x"]}'
            );
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
            $mock->shouldReceive('generateText')->once()->andReturn('{"decision":"maybe"}');
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
            $mock->shouldReceive('generateText')->once()->andReturn('{"decision":"skip"}');
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
            $mock->shouldReceive('generateText')->once()->andReturn(
                json_encode(['decision' => 'capture', 'title' => $overlong, 'body' => $overlong, 'tags' => []])
            );
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
            $mock->shouldReceive('generateText')->andThrow(new \App\Exceptions\NoAiProviderException());
        });

        $response = $this->withToken($token)->postJson('/v1/recall/auto-capture', [
            'transcript_excerpt' => 'test',
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('No AI provider', $response->json('error'));
    }
}
