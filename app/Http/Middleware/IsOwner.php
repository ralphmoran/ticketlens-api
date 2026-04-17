<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('console.login');
        }

        if (! $user->is_owner) {
            return redirect()->route('console.dashboard');
        }

        return $next($request);
    }
}
