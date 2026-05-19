<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseActivationController extends Controller
{
    public function activate(Request $request): JsonResponse
    {
        $key = $request->string('license_key')->value();

        if (!$key) {
            return response()->json(['activated' => false, 'valid' => false, 'error' => 'license_key is required'], 422);
        }

        $license = License::with('user:id,name,email')
            ->where('lemon_key_hash', hash('sha256', $key))
            ->first();

        if (!$license || !$license->isActive()) {
            return response()->json(['activated' => false, 'valid' => false, 'error' => 'license_key not found'], 404);
        }

        return response()->json([
            'activated'   => true,
            'valid'       => true,
            'license_key' => ['key' => $key, 'status' => $license->status],
            'instance'    => ['id' => (string) $license->id],
            'meta'        => [
                'variant_name'   => ucfirst($license->tier),
                'customer_email' => $license->user?->email,
                'ends_at'        => $license->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function validate(Request $request): JsonResponse
    {
        $key = $request->string('license_key')->value();

        if (!$key) {
            return response()->json(['valid' => false, 'error' => 'license_key is required'], 422);
        }

        $license = License::where('lemon_key_hash', hash('sha256', $key))->first();

        if (!$license || !$license->isActive()) {
            return response()->json(['valid' => false, 'error' => 'license_key not found'], 404);
        }

        return response()->json([
            'valid'       => true,
            'license_key' => ['key' => $key, 'status' => $license->status],
            'meta'        => ['ends_at' => $license->expires_at?->toIso8601String()],
        ]);
    }
}
