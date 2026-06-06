<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\User;
use App\Services\AiService;
use App\Services\LicenseValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Compliance is Team-tier only — mock a team license with user
        $user = User::factory()->create();
        $teamLicense = (new License)->forceFill([
            'tier'       => 'team',
            'status'     => 'active',
            'expires_at' => null,
            'user_id'    => $user->id,
        ]);
        $this->mock(LicenseValidationService::class, fn ($m) => $m->shouldReceive('validate')->andReturn($teamLicense));
    }

    public function test_returns_422_when_brief_missing(): void
    {
        $response = $this->withToken('valid-key')->postJson('/v1/compliance', [
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['brief']);
    }

    public function test_returns_422_when_brief_too_long(): void
    {
        $response = $this->withToken('valid-key')->postJson('/v1/compliance', [
            'brief'     => str_repeat('x', 50_001),
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(422);
    }

    public function test_returns_422_when_ticket_key_invalid_format(): void
    {
        $response = $this->withToken('valid-key')->postJson('/v1/compliance', [
            'brief'     => 'Some brief text',
            'ticketKey' => 'invalid key; rm -rf',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ticketKey']);
    }

    public function test_returns_200_with_compliance_result(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->once()->andReturn(
                "Must validate email | FOUND\nMust validate email | FOUND"
            );
        });

        $response = $this->withToken('valid-key')->postJson('/v1/compliance', [
            'brief'     => "# Acceptance Criteria\n- Must validate email",
            'ticketKey' => 'PROJ-123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['requirements', 'results', 'coveragePercent']);
    }

    public function test_returns_401_without_auth_header(): void
    {
        $this->mock(LicenseValidationService::class, fn($m) => $m->shouldReceive('validate')->andReturn(null));

        $response = $this->postJson('/v1/compliance', [
            'brief'     => 'Some brief',
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(401);
    }

    public function test_returns_503_when_user_has_no_ai_providers(): void
    {
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->andThrow(new \App\Exceptions\NoAiProviderException());
        });

        $response = $this->withToken('valid-key')->postJson('/v1/compliance', [
            'brief'     => "# Acceptance Criteria\n- Must validate email",
            'ticketKey' => 'PROJ-123',
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('No AI provider', $response->json('error'));
    }
}
