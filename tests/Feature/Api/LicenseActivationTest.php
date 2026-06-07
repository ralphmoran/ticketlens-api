<?php

namespace Tests\Feature\Api;

use App\Models\License;
use App\Models\User;
use App\Services\LicenseIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    private function issueKey(string $tier = 'pro'): array
    {
        $owner = User::factory()->create(['is_owner' => true, 'tier' => 'owner', 'permissions' => 0]);
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 0]);

        return app(LicenseIssuanceService::class)->issue($owner, $user, $tier, null, null, false);
    }

    // --- activate ---

    public function test_activate_returns_success_for_valid_pro_key(): void
    {
        $result = $this->issueKey('pro');

        $this->postJson('/v1/licenses/activate', [
            'license_key'   => $result['raw_key'],
            'instance_name' => 'test-host',
        ])
            ->assertOk()
            ->assertJson(['activated' => true, 'valid' => true])
            ->assertJsonPath('meta.variant_name', 'Pro')
            ->assertJsonPath('license_key.status', 'active');
    }

    public function test_activate_returns_team_tier_for_team_license(): void
    {
        $result = $this->issueKey('team');

        $this->postJson('/v1/licenses/activate', [
            'license_key'   => $result['raw_key'],
            'instance_name' => 'test-host',
        ])
            ->assertOk()
            ->assertJsonPath('meta.variant_name', 'Team');
    }

    public function test_activate_returns_404_for_unknown_key(): void
    {
        $this->postJson('/v1/licenses/activate', [
            'license_key'   => 'TL-00000000-0000-0000-0000-000000000000',
            'instance_name' => 'test-host',
        ])
            ->assertNotFound()
            ->assertJson(['activated' => false, 'valid' => false]);
    }

    public function test_activate_returns_404_for_cancelled_license(): void
    {
        $result = $this->issueKey();
        $result['license']->update(['status' => 'cancelled']);

        $this->postJson('/v1/licenses/activate', [
            'license_key'   => $result['raw_key'],
            'instance_name' => 'test-host',
        ])
            ->assertNotFound();
    }

    public function test_activate_returns_422_when_license_key_missing(): void
    {
        $this->postJson('/v1/licenses/activate', ['instance_name' => 'test-host'])
            ->assertUnprocessable();
    }

    // --- validate ---

    public function test_validate_returns_valid_for_active_key(): void
    {
        $result = $this->issueKey();

        $this->postJson('/v1/licenses/validate', [
            'license_key'   => $result['raw_key'],
            'instance_name' => 'test-host',
        ])
            ->assertOk()
            ->assertJson(['valid' => true]);
    }

    public function test_validate_returns_404_for_unknown_key(): void
    {
        $this->postJson('/v1/licenses/validate', [
            'license_key'   => 'TL-00000000-0000-0000-0000-000000000000',
            'instance_name' => 'test-host',
        ])
            ->assertNotFound()
            ->assertJson(['valid' => false]);
    }

    public function test_validate_returns_404_for_cancelled_license(): void
    {
        $result = $this->issueKey();
        $result['license']->update(['status' => 'cancelled']);

        $this->postJson('/v1/licenses/validate', [
            'license_key'   => $result['raw_key'],
            'instance_name' => 'test-host',
        ])
            ->assertNotFound();
    }

    public function test_validate_returns_422_when_license_key_missing(): void
    {
        $this->postJson('/v1/licenses/validate', [])
            ->assertUnprocessable();
    }

    // --- LicenseValidationService TL- key routing ---

    public function test_activate_does_not_expose_customer_email(): void
    {
        $result = $this->issueKey('pro');

        $response = $this->postJson('/v1/licenses/activate', [
            'license_key'   => $result['raw_key'],
            'instance_name' => 'test-host',
        ])->assertOk();

        $this->assertArrayNotHasKey('customer_email', $response->json('meta') ?? []);
    }

    public function test_auth_license_middleware_accepts_tl_prefixed_key(): void
    {
        $result = $this->issueKey('pro');

        // POST /v1/digest/deliver is behind auth.license — a 422 (missing fields) means auth passed
        $this->withHeaders(['Authorization' => 'Bearer ' . $result['raw_key']])
            ->postJson('/v1/digest/deliver', [])
            ->assertUnprocessable();
    }

    public function test_auth_license_middleware_rejects_unknown_tl_key(): void
    {
        // Force real license validation — local .env may have TICKETLENS_SKIP_LICENSE=true
        Config::set('ticketlens.skip_license', false);

        $this->withHeaders(['Authorization' => 'Bearer TL-00000000-0000-0000-0000-000000000000'])
            ->postJson('/v1/digest/deliver', [])
            ->assertUnauthorized();
    }
}
