<?php

namespace Tests\Feature;

use App\Models\CliToken;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamUserWithToken(): array
    {
        $user      = User::factory()->create(['tier' => 'team']);
        $plaintext = 'tl_' . str_repeat('c', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);
        return [$user, $plaintext];
    }

    public function test_returns_422_when_brief_missing(): void
    {
        [, $token] = $this->makeTeamUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/compliance', [
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['brief']);
    }

    public function test_returns_422_when_brief_too_long(): void
    {
        [, $token] = $this->makeTeamUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/compliance', [
            'brief'     => str_repeat('x', 50_001),
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(422);
    }

    public function test_returns_422_when_ticket_key_invalid_format(): void
    {
        [, $token] = $this->makeTeamUserWithToken();

        $response = $this->withToken($token)->postJson('/v1/compliance', [
            'brief'     => 'Some brief text',
            'ticketKey' => 'invalid key; rm -rf',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ticketKey']);
    }

    public function test_returns_200_with_compliance_result(): void
    {
        [, $token] = $this->makeTeamUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->once()->andReturn(
                "Must validate email | FOUND\nMust validate email | FOUND"
            );
        });

        $response = $this->withToken($token)->postJson('/v1/compliance', [
            'brief'     => "# Acceptance Criteria\n- Must validate email",
            'ticketKey' => 'PROJ-123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['requirements', 'results', 'coveragePercent']);
    }

    public function test_returns_200_for_a_digit_prefixed_ticket_key(): void
    {
        [, $token] = $this->makeTeamUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->once()->andReturn(
                "Must validate email | FOUND\nMust validate email | FOUND"
            );
        });

        $response = $this->withToken($token)->postJson('/v1/compliance', [
            'brief'     => "# Acceptance Criteria\n- Must validate email",
            'ticketKey' => 'CNV1-2',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['requirements', 'results', 'coveragePercent']);
    }

    public function test_returns_401_without_auth_header(): void
    {
        $response = $this->postJson('/v1/compliance', [
            'brief'     => 'Some brief',
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(401);
    }

    public function test_returns_403_for_pro_tier_user(): void
    {
        $user      = User::factory()->create(['tier' => 'pro']);
        $plaintext = 'tl_' . str_repeat('p', 40);
        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);

        $response = $this->withToken($plaintext)->postJson('/v1/compliance', [
            'brief'     => 'Some brief',
            'ticketKey' => 'PROJ-123',
        ]);
        $response->assertStatus(403);
    }

    public function test_returns_503_when_user_has_no_ai_providers(): void
    {
        [, $token] = $this->makeTeamUserWithToken();
        $this->mock(AiService::class, function ($mock) {
            $mock->shouldReceive('summarize')->andThrow(new \App\Exceptions\NoAiProviderException());
        });

        $response = $this->withToken($token)->postJson('/v1/compliance', [
            'brief'     => "# Acceptance Criteria\n- Must validate email",
            'ticketKey' => 'PROJ-123',
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('No AI provider', $response->json('error'));
    }
}
