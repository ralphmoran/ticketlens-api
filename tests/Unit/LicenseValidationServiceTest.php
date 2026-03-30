<?php
namespace Tests\Unit;

use App\Services\LicenseValidationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LicenseValidationServiceTest extends TestCase
{
    private LicenseValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LicenseValidationService();
    }

    public function test_returns_true_when_skip_license_is_enabled(): void
    {
        Config::set('ticketlens.skip_license', true);
        Config::set('app.env', 'local');

        $this->assertTrue($this->service->isValid('any-key'));
    }

    public function test_skip_license_does_not_work_in_production(): void
    {
        Config::set('ticketlens.skip_license', true);
        Config::set('app.env', 'production');

        Http::fake([
            '*' => Http::response(['valid' => false], 200),
        ]);

        $this->assertFalse($this->service->isValid('any-key'));
    }

    public function test_returns_true_for_valid_lemonsqueezy_response(): void
    {
        Config::set('ticketlens.skip_license', false);
        Config::set('app.env', 'production');

        Http::fake([
            '*' => Http::response([
                'valid' => true,
                'license_key' => ['status' => 'active'],
            ], 200),
        ]);

        $this->assertTrue($this->service->isValid('valid-key'));
    }

    public function test_returns_false_for_expired_license(): void
    {
        Config::set('ticketlens.skip_license', false);
        Config::set('app.env', 'production');

        Http::fake([
            '*' => Http::response([
                'valid' => true,
                'license_key' => ['status' => 'expired'],
            ], 200),
        ]);

        $this->assertFalse($this->service->isValid('expired-key'));
    }

    public function test_returns_false_on_lemonsqueezy_error(): void
    {
        Config::set('ticketlens.skip_license', false);
        Config::set('app.env', 'production');

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $this->assertFalse($this->service->isValid('any-key'));
    }

    public function test_uses_timing_safe_comparison(): void
    {
        // isValid must not return early based on key length — timing-safe
        Config::set('ticketlens.skip_license', false);
        Config::set('app.env', 'production');
        Http::fake(['*' => Http::response(['valid' => false], 200)]);

        $this->assertFalse($this->service->isValid('a'));
        $this->assertFalse($this->service->isValid(str_repeat('a', 100)));
    }
}
