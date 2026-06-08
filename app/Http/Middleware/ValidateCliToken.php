<?php

namespace App\Http\Middleware;

use App\Models\CliToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ValidateCliToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $request->bearerToken();
        $ip        = $request->ip();
        $lockKey   = "auth-cli-fail:{$ip}";

        // Check lockout (5 consecutive failures → 15-minute block)
        if (RateLimiter::tooManyAttempts($lockKey, 5)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$plaintext) {
            RateLimiter::hit($lockKey, 900);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = CliToken::findByPlaintext($plaintext);
        if ($token) {
            $token->load('user');
        }

        if (!$token) {
            RateLimiter::hit($lockKey, 900);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Successful auth — clear failure count and record usage
        RateLimiter::clear($lockKey);
        $token->updateQuietly(['last_used_at' => now()]);

        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }
}
