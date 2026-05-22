<?php
namespace App\Services;

use App\Models\License;
use Illuminate\Support\Facades\Http;

class LicenseValidationService
{
    public function isValid(string $key): bool
    {
        // Skip flag only works in local/testing — never staging or production
        if (in_array(config('app.env'), ['local', 'testing']) && config('ticketlens.skip_license', false)) {
            return true;
        }

        // Owner-issued keys (TL- prefix) are stored locally — check DB directly
        if (str_starts_with($key, 'TL-')) {
            $license = License::where('lemon_key_hash', hash('sha256', $key))->first();
            return $license && $license->isActive();
        }

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
            $isValid = ($data['valid'] ?? false) === true;
            $isActive = ($data['license_key']['status'] ?? '') !== 'expired';

            return $isValid && $isActive;
        } catch (\Throwable) {
            return false;
        }
    }
}
