<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class LicenseValidationService
{
    public function isValid(string $key): bool
    {
        // Skip flag only works in local environment — never production
        if (config('app.env') !== 'production' && config('ticketlens.skip_license', false)) {
            return true;
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
