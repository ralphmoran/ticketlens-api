<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.license' => \App\Http\Middleware\ValidateLicenseKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // Let Laravel handle validation + HTTP exceptions natively (they have safe, structured responses)
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                return null;
            }

            if ($request->is('v1/*')) {
                // Never expose internal error detail on API routes
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                return response()->json(['error' => 'Request failed'], $status);
            }
        });
    })->create();
