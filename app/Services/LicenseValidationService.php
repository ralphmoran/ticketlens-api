<?php
namespace App\Services;

use App\Models\License;
use Illuminate\Support\Facades\Http;

class LicenseValidationService
{
    /**
     * Validate the key and return the License if active, null otherwise.
     * Callers that need tier information must use this method.
     */
    public function validate(string $key): ?License
    {
        // Skip flag: local/testing only — never staging or production
        if (in_array(config('app.env'), ['local', 'testing']) && config('ticketlens.skip_license', false)) {
            return (new License)->forceFill(['tier' => 'pro', 'status' => 'active', 'expires_at' => null]);
        }

        // Both TL- (owner-issued) and LemonSqueezy keys are stored by hash via webhook.
        $license = License::where('lemon_key_hash', hash('sha256', $key))->first();
        if ($license && $license->isActive()) {
            return $license;
        }

        // LemonSqueezy key not yet in DB (webhook hasn't fired) — HTTP fallback.
        // Tier is unknown in this path; minimum privilege (free) is applied.
        if (!str_starts_with($key, 'TL-') && $this->isValidViaHttp($key)) {
            return (new License)->forceFill(['tier' => 'free', 'status' => 'active', 'expires_at' => null]);
        }

        return null;
    }

    /**
     * Simple boolean check — delegates to validate() to avoid duplication.
     */
    public function isValid(string $key): bool
    {
        return $this->validate($key) !== null;
    }

    private function isValidViaHttp(string $key): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/json'])
                ->post(config('services.lemonsqueezy.validate_url'), [
                    'license_key' => $key,
                ]);

            if (!$response->successful()) {
                return false;
            }

            $data = $response->json();

            // Timing-safe: always evaluate all conditions, never short-circuit on key content
            $isValid  = ($data['valid'] ?? false) === true;
            $isActive = ($data['license_key']['status'] ?? '') !== 'expired';

            return $isValid && $isActive;
        } catch (\Throwable) {
            return false;
        }
    }
}
