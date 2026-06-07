<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLicenseTier
{
    // Ordered lowest to highest — position determines rank
    private const HIERARCHY = ['free', 'pro', 'team'];

    public function handle(Request $request, Closure $next, string $required): Response
    {
        $license = $request->attributes->get('license');
        // auth.cli routes have no license object — fall back to the user's own tier
        $tier = $license?->tier ?? $request->user()?->tier;

        if (!$tier || !$this->meetsMinimum($tier, $required)) {
            return response()->json(['error' => 'Insufficient license tier.'], 403);
        }

        return $next($request);
    }

    private function meetsMinimum(string $actual, string $required): bool
    {
        $actualRank   = array_search($actual, self::HIERARCHY, true);
        $requiredRank = array_search($required, self::HIERARCHY, true);

        if ($actualRank === false || $requiredRank === false) {
            return false;
        }

        return $actualRank >= $requiredRank;
    }
}
