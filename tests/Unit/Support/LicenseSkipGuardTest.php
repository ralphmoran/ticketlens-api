<?php
namespace Tests\Unit\Support;

use App\Support\LicenseSkipGuard;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class LicenseSkipGuardTest extends TestCase
{
    public function test_production_with_skip_license_true_throws_and_logs_critical(): void
    {
        Log::shouldReceive('critical')->once();
        $this->expectException(RuntimeException::class);

        LicenseSkipGuard::assertSafe('production', true);
    }

    public function test_production_with_skip_license_false_boots_normally(): void
    {
        Log::shouldReceive('critical')->never();

        LicenseSkipGuard::assertSafe('production', false);

        $this->assertTrue(true);
    }

    public function test_local_with_skip_license_true_is_allowed(): void
    {
        Log::shouldReceive('critical')->never();

        LicenseSkipGuard::assertSafe('local', true);

        $this->assertTrue(true);
    }

    public function test_staging_with_skip_license_true_is_allowed(): void
    {
        // Scoped strictly to 'production' per audit acceptance criteria —
        // LicenseValidationService already restricts the actual bypass to
        // local/testing only; this guard's job is the boot-time trip-wire,
        // not re-deriving the full environment allowlist.
        Log::shouldReceive('critical')->never();

        LicenseSkipGuard::assertSafe('staging', true);

        $this->assertTrue(true);
    }

    public function test_mixed_case_production_with_skip_license_true_still_throws(): void
    {
        Log::shouldReceive('critical')->once();
        $this->expectException(RuntimeException::class);

        LicenseSkipGuard::assertSafe('Production', true);
    }
}
