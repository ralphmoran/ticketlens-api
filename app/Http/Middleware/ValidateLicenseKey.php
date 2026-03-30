<?php
namespace App\Http\Middleware;

use App\Services\LicenseValidationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ValidateLicenseKey
{
    public function __construct(private readonly LicenseValidationService $validator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $ip = $request->ip();
        $lockKey = "auth-fail:{$ip}";

        // Check lockout (5 consecutive failures → 15-minute block)
        if (RateLimiter::tooManyAttempts($lockKey, 5)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$token || !$this->validator->isValid($token)) {
            RateLimiter::hit($lockKey, 900); // 15-minute window
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Successful auth — clear failure count
        RateLimiter::clear($lockKey);

        return $next($request);
    }
}
