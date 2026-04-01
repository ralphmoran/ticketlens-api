<?php

namespace Tests\Feature;

use App\Services\AnthropicService;
use App\Services\LicenseValidationService;
use Tests\TestCase;

class ComplianceControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LicenseValidationService::class, fn($m) => $m->shouldReceive('isValid')->andReturn(true));
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
        $this->mock(AnthropicService::class, function ($mock) {
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
        $this->mock(LicenseValidationService::class, fn($m) => $m->shouldReceive('isValid')->andReturn(false));

        $response = $this->postJson('/v1/compliance', [
            'brief'     => 'Some brief',
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(401);
    }
}
