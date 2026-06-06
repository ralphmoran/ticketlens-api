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

    /** Helper — creates a persisted User with RefreshDatabase trait active. */
    private function makeUser(): User
    {
        return User::factory()->create();
    }
}
