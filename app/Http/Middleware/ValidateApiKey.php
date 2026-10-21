<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a public-API key and requires every scope named in the route
 * definition, e.g. `auth.api-key:recall:read`.
 */
class ValidateApiKey
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $lockKey = 'auth-api-key-fail:' . $request->ip();

        // 5 consecutive failures → 15-minute block, same policy as CLI tokens
        if (RateLimiter::tooManyAttempts($lockKey, 5)) {
            return $this->unauthorized();
        }

        $plaintext = $request->bearerToken();
        $key = $plaintext ? ApiKey::findActiveByPlaintext($plaintext) : null;

        if (! $key) {
            RateLimiter::hit($lockKey, 900);

            return $this->unauthorized();
        }

        RateLimiter::clear($lockKey);

        $user = $key->user;

        if (! $user || $user->suspended_at) {
            return $this->unauthorized();
        }

        // Re-checked on every call: a downgraded account must not keep working keys.
        if (! $user->is_owner && ! in_array($user->tier, ApiKey::ALLOWED_TIERS, true)) {
            return response()->json(['error' => 'Insufficient license tier.'], 403);
        }

        foreach ($scopes as $scope) {
            if (! $key->hasScope($scope)) {
                return response()->json(['error' => 'Insufficient scope'], 403);
            }
        }

        // At most one write per minute per key.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->updateQuietly(['last_used_at' => now()]);
        }

        $request->attributes->set('api_key', $key);
        $request->setUserResolver(fn () => $key->user);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
