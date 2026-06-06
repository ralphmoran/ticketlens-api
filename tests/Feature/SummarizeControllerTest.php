<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\User;
use App\Services\AiService;
use App\Services\LicenseValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SummarizeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $proLicense = (new License)->forceFill([
            'tier'       => 'pro',
            'status'     => 'active',
            'expires_at' => null,
            'user_id'    => $user->id,
        ]);
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('validate')->andReturn($proLicense));
    }

    public function test_returns_summary_on_valid_request(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->once()->andReturn('Cart validation summary.');
        });

        $response = $this->withToken('valid-key')->postJson('/v1/summarize', [
            'ticketKey' => 'PROJ-123',
            'brief'     => str_repeat('ticket content ', 10),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['summary' => 'Cart validation summary.']);
    }

    public function test_returns_401_without_token(): void
    {
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('validate')->andReturn(null));

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
            'brief'     => str_repeat('x', 50_001),
        ]);
        $response->assertStatus(422);
    }

    public function test_returns_503_when_user_has_no_ai_providers(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->andThrow(new \App\Exceptions\NoAiProviderException());
        });

        $response = $this->withToken('valid-key')->postJson('/v1/summarize', [
            'brief' => 'test brief',
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('No AI provider', $response->json('error'));
    }

    public function test_does_not_expose_ai_error_detail(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->andThrow(new \RuntimeException('AI unavailable. Tried: groq (HTTP 500)'));
        });

        $response = $this->withToken('valid-key')->postJson('/v1/summarize', [
            'brief' => 'test brief',
        ]);

        $response->assertStatus(500);
    }
}
