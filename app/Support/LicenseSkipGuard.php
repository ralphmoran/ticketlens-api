<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class LicenseSkipGuard
{
    /**
     * Boot-time trip-wire: refuses to boot when license enforcement is
     * disabled in production. LicenseValidationService already scopes the
     * actual skip_license bypass to local/testing only, so this cannot be
     * exploited by today's code — this guard exists as defense-in-depth
     * against a future second consumer of the flag that doesn't replicate
     * that scoping (audit 2026-07-07 §4.2).
     */
    public static function assertSafe(string $environment, bool $skipLicense): void
    {
        if (strtolower($environment) !== 'production' || ! $skipLicense) {
            return;
        }

        Log::critical('TICKETLENS_SKIP_LICENSE=true detected in production — refusing to boot.');

        throw new RuntimeException(
            'TICKETLENS_SKIP_LICENSE=true is not allowed in production. Remove it from the environment and redeploy.'
        );
    }
}
