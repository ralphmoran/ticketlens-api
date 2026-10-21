<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records one audit row per public-API write call. Runs after the response is sent,
 * so the final status is known and the caller never waits on the insert.
 */
class AuditApiKeyCall
{
    public function __construct(private readonly AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $key = $request->attributes->get('api_key');

        if (! $key) {
            return;
        }

        $this->audit->logFromRequest($request, 'api_key.call', metadata: [
            'api_key_id' => $key->id,
            'method'     => $request->method(),
            'path'       => $request->path(),
            'status'     => $response->getStatusCode(),
        ]);
    }
}
