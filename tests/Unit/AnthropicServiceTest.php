<?php
namespace Tests\Unit;

use App\Services\AnthropicService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicServiceTest extends TestCase
{
    public function test_returns_summary_string_on_success(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['text' => 'This ticket adds cart validation.']],
            ], 200),
        ]);

        $service = new AnthropicService();
        $result = $service->summarize('# PROJ-1\nFix empty cart');

        $this->assertEquals('This ticket adds cart validation.', $result);
    }

    public function test_throws_on_non_200_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/anthropic api error: 401/i');

        (new AnthropicService())->summarize('brief');
    }

    public function test_sends_correct_model_and_headers(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['text' => 'summary']],
            ], 200),
        ]);

        (new AnthropicService())->summarize('brief');

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key')
                && $request->hasHeader('anthropic-version')
                && str_contains($request->body(), 'claude-haiku');
        });
    }

    public function test_brief_is_stripped_of_null_bytes(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['text' => 'ok']],
            ], 200),
        ]);

        // Should not throw — null bytes stripped before sending
        (new AnthropicService())->summarize("brief\x00with null");

        Http::assertSent(function ($request) {
            return !str_contains($request->body(), "\x00");
        });
    }
}
