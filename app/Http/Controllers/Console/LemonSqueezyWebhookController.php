<?php

namespace App\Http\Controllers\Console;

use App\Enums\Permission;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class LemonSqueezyWebhookController
{
    private const TIER_MAP = [
        'pro'        => ['tier' => 'pro',        'permissions_fn' => 'pro'],
        'team'       => ['tier' => 'team',       'permissions_fn' => 'team'],
        'enterprise' => ['tier' => 'enterprise', 'permissions_fn' => 'enterprise'],
    ];

    public function handle(Request $request): Response
    {
        $secret    = config('services.lemonsqueezy.signing_secret');
        $signature = $request->header('X-Signature');
        $payload   = $request->getContent();

        if (! $this->verifySignature($payload, $signature, $secret)) {
            Log::warning('LemonSqueezy webhook: invalid signature');
            return response('Forbidden', 403);
        }

        $data      = $request->json()->all();
        $eventName = $data['meta']['event_name'] ?? null;

        if (! str_starts_with((string) $eventName, 'subscription_')) {
            return response('OK', 200);
        }

        $userId = $data['meta']['custom_data']['user_id'] ?? null;
        $email  = $data['data']['attributes']['user_email'] ?? null;
        $user   = $userId
            ? User::find($userId)
            : ($email ? User::where('email', $email)->first() : null);

        if (! $user) {
            Log::warning('LemonSqueezy webhook: user not found', compact('userId', 'email'));
            return response('OK', 200);
        }

        match ($eventName) {
            'subscription_created', 'subscription_updated'                => $this->activateSubscription($user, $data),
            'subscription_cancelled', 'subscription_expired'              => $this->deactivateSubscription($user),
            'subscription_paused', 'subscription_unpaused'                => $this->pauseSubscription($user, $eventName),
            default                                                        => null,
        };

        return response('OK', 200);
    }

    private function verifySignature(string $payload, ?string $signature, ?string $secret): bool
    {
        if (! $secret || ! $signature) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    private function activateSubscription(User $user, array $data): void
    {
        $productName = strtolower($data['data']['attributes']['product_name'] ?? '');
        $tierKey     = $this->resolveTierKey($productName);
        $tierConfig  = self::TIER_MAP[$tierKey] ?? null;

        if (! $tierConfig) {
            Log::warning('LemonSqueezy webhook: unknown product', ['product' => $productName]);
            return;
        }

        $fn             = $tierConfig['permissions_fn'];
        $newPermissions = ($user->permissions & Permission::adminMask()) | Permission::{$fn}();

        $user->update([
            'tier'        => $tierConfig['tier'],
            'permissions' => $newPermissions,
        ]);

        $rawKey = $data['data']['attributes']['identifier'] ?? null;
        if ($rawKey) {
            License::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'lemon_key_hash' => hash('sha256', $rawKey),
                    'status'         => 'active',
                    'tier'           => $tierConfig['tier'],
                    'expires_at'     => null,
                ]
            );
        }
    }

    private function deactivateSubscription(User $user): void
    {
        $newPermissions = ($user->permissions & Permission::adminMask()) | Permission::free();

        $user->update([
            'tier'        => 'free',
            'permissions' => $newPermissions,
        ]);

        License::where('user_id', $user->id)->update(['status' => 'cancelled']);
    }

    private function pauseSubscription(User $user, string $event): void
    {
        $status = $event === 'subscription_paused' ? 'paused' : 'active';
        License::where('user_id', $user->id)->update(['status' => $status]);
    }

    private function resolveTierKey(string $productName): string
    {
        foreach (array_keys(self::TIER_MAP) as $key) {
            if (str_contains($productName, $key)) {
                return $key;
            }
        }

        return 'pro';
    }
}
