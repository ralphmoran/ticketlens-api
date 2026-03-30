<?php
namespace Tests\Feature;

use App\Services\LicenseValidationService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ValidateLicenseKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Register a test-only route protected by the middleware
        Route::middleware('auth.license')->get('/test-auth', fn() => response()->json(['ok' => true]));
    }

    public function test_returns_401_when_no_authorization_header(): void
    {
        $response = $this->getJson('/test-auth');
        $response->assertStatus(401);
        $response->assertJson(['error' => 'Unauthorized']);
    }

    public function test_returns_401_when_bearer_token_is_invalid(): void
    {
        $this->mock(LicenseValidationService::class, function ($mock) {
            $mock->shouldReceive('isValid')->once()->andReturn(false);
        });

        $response = $this->withToken('bad-key')->getJson('/test-auth');
        $response->assertStatus(401);
    }

    public function test_allows_request_when_token_is_valid(): void
    {
        $this->mock(LicenseValidationService::class, function ($mock) {
            $mock->shouldReceive('isValid')->once()->andReturn(true);
        });

        $response = $this->withToken('valid-key')->getJson('/test-auth');
        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);
    }

    public function test_does_not_leak_license_key_in_error_response(): void
    {
        $response = $this->withToken('secret-key')->getJson('/test-auth');
        $this->assertStringNotContainsString('secret-key', $response->getContent());
    }
}
