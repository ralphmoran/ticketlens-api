<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HasPermission
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $bit  = Permission::fromName($permission)->value;

        if ($user === null) {
            return redirect()->route('console.login');
        }

        if (! $this->permissions->can($user, $bit)) {
            if ($request->expectsJson() || $request->header('X-Inertia')) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return redirect()->route('console.upgrade');
        }

        return $next($request);
    }
}
