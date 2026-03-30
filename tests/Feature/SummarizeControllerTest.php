<?php
namespace Tests\Feature;

use App\Services\AnthropicService;
use App\Services\LicenseValidationService;
use Tests\TestCase;

class SummarizeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LicenseValidationService::class, fn($m) => $m->shouldReceive('isValid')->andReturn(true));
    }

    public function test_returns_summary_on_valid_request(): void
    {
        $this->mock(AnthropicService::class, function ($mock) {
            $mock->shouldReceive('summarize')->once()->andReturn('Cart validation summary.');
        });

        $response = $this->withToken('valid-key')->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
            'brief' => str_repeat('ticket content ', 10),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['summary' => 'Cart validation summary.']);
    }

    public function test_returns_401_without_token(): void
    {
        $this->mock(LicenseValidationService::class, fn($m) => $m->shouldReceive('isValid')->andReturn(false));

        $response = $this->postJson('/v1/summarize', ['brief' => 'test']);
        $response->assertStatus(401);
    }

    public function test_returns_422_when_brief_missing(): void
    {
        $response = $this->withToken('valid-key')->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['brief']);
    }

    public function test_returns_422_when_brief_exceeds_50k_chars(): void
    {
        $response = $this->withToken('valid-key')->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
            'brief' => str_repeat('x', 50_001),
        ]);
        $response->assertStatus(422);
    }

    public function test_does_not_expose_anthropic_error_detail(): void
    {
        $this->mock(AnthropicService::class, function ($mock) {
            $mock->shouldReceive('summarize')->andThrow(new \RuntimeException('Anthropic API error: 500'));
        });

        $response = $this->withToken('valid-key')->postJson('/v1/summarize', [
            'brief' => 'test brief',
        ]);

        $response->assertStatus(500);
        // Generic error message only — no Anthropic detail leaked
        $this->assertStringNotContainsString('Anthropic', $response->getContent());
    }
}
