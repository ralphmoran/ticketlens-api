<?php

namespace Tests\Feature\Middleware;

use App\Models\License;
use App\Services\LicenseValidationService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequireLicenseTierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth.license', 'license.tier:pro'])
            ->get('/test-tier-pro', fn () => response()->json(['ok' => true]));

        Route::middleware(['auth.license', 'license.tier:team'])
            ->get('/test-tier-team', fn () => response()->json(['ok' => true]));
    }

    private function mockValidate(?License $license): void
    {
        $this->mock(LicenseValidationService::class, function ($mock) use ($license) {
            $mock->shouldReceive('validate')->andReturn($license);
        });
    }

    private function fakeLicense(string $tier): License
    {
        return (new License)->forceFill(['tier' => $tier, 'status' => 'active', 'expires_at' => null]);
    }

    // ── Free tier attacking Pro endpoint ─────────────────────────────────────

    public function test_free_tier_cannot_access_pro_endpoint(): void
    {
        $this->mockValidate($this->fakeLicense('free'));

        $this->withToken('free-key')->getJson('/test-tier-pro')
            ->assertStatus(403)
            ->assertJson(['error' => 'Insufficient license tier.']);
    }

    // ── Pro tier accessing Pro endpoint ──────────────────────────────────────

    public function test_pro_tier_can_access_pro_endpoint(): void
    {
        $this->mockValidate($this->fakeLicense('pro'));

        $this->withToken('pro-key')->getJson('/test-tier-pro')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    // ── Team tier accessing Pro endpoint (upward compat) ─────────────────────

    public function test_team_tier_can_access_pro_endpoint(): void
    {
        $this->mockValidate($this->fakeLicense('team'));

        $this->withToken('team-key')->getJson('/test-tier-pro')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    // ── Pro tier attacking Team endpoint ─────────────────────────────────────

    public function test_pro_tier_cannot_access_team_endpoint(): void
    {
        $this->mockValidate($this->fakeLicense('pro'));

        $this->withToken('pro-key')->getJson('/test-tier-team')
            ->assertStatus(403)
            ->assertJson(['error' => 'Insufficient license tier.']);
    }

    // ── Team tier accessing Team endpoint ────────────────────────────────────

    public function test_team_tier_can_access_team_endpoint(): void
    {
        $this->mockValidate($this->fakeLicense('team'));

        $this->withToken('team-key')->getJson('/test-tier-team')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    // ── No license (invalid key) ─────────────────────────────────────────────

    public function test_invalid_key_returns_401_not_403(): void
    {
        $this->mockValidate(null);

        $this->withToken('garbage')->getJson('/test-tier-pro')
            ->assertStatus(401);
    }

    // ── Unknown tier string treated as below free ────────────────────────────

    public function test_unknown_tier_cannot_access_pro_endpoint(): void
    {
        $this->mockValidate($this->fakeLicense('unknown'));

        $this->withToken('weird-key')->getJson('/test-tier-pro')
            ->assertStatus(403);
    }
}
