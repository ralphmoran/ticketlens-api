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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust reverse-proxy headers from RFC-1918 private ranges only.
        // Covers Docker bridge (172.16–172.31), local dev (127.0.0.1),
        // and common cloud VPC ranges. Prevents untrusted containers from
        // spoofing X-Forwarded-For to bypass IP-based rate limiting.
        // For ngrok or external load balancers add their CIDR here.
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            // Invalidates a session mid-flight if the account's password hash changed
            // elsewhere — the mechanism Auth::logoutOtherDevices() in
            // AccountController::updatePassword() relies on to actually revoke sessions.
            \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('console.login'));
        $middleware->redirectUsersTo(fn () => route('console.dashboard'));
        // LemonSqueezy posts without a browser session — HMAC signature is the auth mechanism
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->alias([
            'auth.license'     => \App\Http\Middleware\ValidateLicenseKey::class,
            'auth.cli'         => \App\Http\Middleware\ValidateCliToken::class,
            'license.tier'     => \App\Http\Middleware\RequireLicenseTier::class,
            'permission'       => \App\Http\Middleware\HasPermission::class,
            'owner'            => \App\Http\Middleware\IsOwner::class,
            'team.manager'     => \App\Http\Middleware\EnsureTeamManager::class,
            'team.lead'        => \App\Http\Middleware\EnsureTeamLead::class,
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
                if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    return response()->json(['error' => 'Not found'], 404);
                }
                // Never expose internal error detail on API routes
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                return response()->json(['error' => 'Request failed'], $status);
            }
        });
    })->create();
