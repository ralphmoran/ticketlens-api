<?php

namespace App\Http\Middleware;

use App\Models\CliToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateCliToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $request->bearerToken();

        if (!$plaintext) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = CliToken::findByPlaintext($plaintext);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token->updateQuietly(['last_used_at' => now()]);

        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }
}
