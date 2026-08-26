<?php

namespace Tests\Unit;

use App\Exceptions\NoAiProviderException;
use App\Models\User;
use App\Models\UserAiProvider;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    use RefreshDatabase;
    private AiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiService();
    }

    public function test_throws_no_provider_exception_when_user_has_no_providers(): void
    {
        $user = $this->makeUser();

        $this->expectException(NoAiProviderException::class);
        $this->service->summarize($user, 'some brief');
    }

    public function test_throws_no_provider_exception_when_all_providers_disabled(): void
    {
        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => false]);

        $this->expectException(NoAiProviderException::class);
        $this->service->summarize($user, 'some brief');
    }

    public function test_calls_anthropic_and_returns_summary(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'Summary text.']],
        ], 200)]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'anthropic', 'enabled' => true]);

        $result = $this->service->summarize($user, 'brief content');

        $this->assertSame('Summary text.', $result);
    }

    public function test_calls_groq_and_returns_summary(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Groq summary.']]],
        ], 200)]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);

        $result = $this->service->summarize($user, 'brief content');

        $this->assertSame('Groq summary.', $result);
    }

    public function test_falls_back_to_second_provider_when_first_fails(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([], 401),
            'api.groq.com/*'      => Http::response([
                'choices' => [['message' => ['content' => 'Groq fallback.']]],
            ], 200),
        ]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'anthropic', 'enabled' => true, 'priority' => 1]);
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true, 'priority' => 2]);

        $result = $this->service->summarize($user, 'brief');

        $this->assertSame('Groq fallback.', $result);
    }

    public function test_throws_runtime_exception_when_all_providers_fail(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([], 401),
            'api.groq.com/*'      => Http::response([], 503),
        ]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'anthropic', 'enabled' => true, 'priority' => 1]);
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true, 'priority' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/AI unavailable\. Tried:/');

        $this->service->summarize($user, 'brief');
    }

    public function test_null_bytes_are_stripped_from_brief(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);

        $this->service->summarize($user, "brief\x00with\x00nulls");

        Http::assertSent(fn($req) => ! str_contains($req->body(), "\x00"));
    }

    public function test_brief_is_truncated_to_50k_chars(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);

        $this->service->summarize($user, str_repeat('x', 60_000));

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            $content = $body['messages'][0]['content'] ?? '';
            return mb_strlen($content) <= 50_200; // 50k brief + prompt prefix
        });
    }

    public function test_generate_text_sends_the_caller_supplied_prompt_verbatim(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'generated']]],
        ], 200)]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);

        $result = $this->service->generateText($user, 'Custom meta-prompt, not a ticket summary request.');

        $this->assertSame('generated', $result);
        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            return $body['messages'][0]['content'] === 'Custom meta-prompt, not a ticket summary request.';
        });
    }

    public function test_generate_text_defaults_to_256_max_tokens(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);

        $this->service->generateText($user, 'plain prompt');

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            return $body['max_tokens'] === 256;
        });
    }

    public function test_generate_text_honors_a_caller_supplied_max_tokens(): void
    {
        // Reasoning models (e.g. Groq's openai/gpt-oss-120b) spend tokens on hidden
        // reasoning before the visible answer — a small budget can leave content
        // empty even on a 200 response. Callers doing real generative work (not a
        // short summary) need to be able to ask for a bigger budget.
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'a longer generated prompt']]],
        ], 200)]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);

        $this->service->generateText($user, 'plain prompt', maxTokens: 1024);

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            return $body['max_tokens'] === 1024;
        });
    }

    public function test_generate_text_does_not_prepend_the_summarize_prompt(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $user = $this->makeUser();
        UserAiProvider::factory()->for($user)->create(['provider' => 'groq', 'enabled' => true]);

        $this->service->generateText($user, 'plain prompt');

        Http::assertSent(function ($req) {
            $body = json_decode($req->body(), true);
            return ! str_contains($body['messages'][0]['content'], 'Summarize this Jira ticket');
        });
    }

    /** Helper — creates a persisted User with RefreshDatabase trait active. */
    private function makeUser(): User
    {
        return User::factory()->create();
    }
}
