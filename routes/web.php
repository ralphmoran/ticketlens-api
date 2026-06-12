<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// M4: per-email counter supplements the route-level IP throttle (throttle:5,1).
// Different IPs submitting the same email address are collectively capped at 5/min.
RateLimiter::for('login-by-email', function ($r) {
    $email = $r->input('email');
    $key = $email ? 'login-by-email:' . strtolower($email) : 'login-by-email-ip:' . $r->ip();
    return Limit::perMinute(5)->by($key);
});

Route::get('/', fn () => response()->file(public_path('landing.html')));
Route::get('/inertia-test', fn () => inertia('Test'));

// LemonSqueezy webhook (public, HMAC-verified inside controller)
Route::post('/webhooks/lemonsqueezy', [\App\Http\Controllers\Console\LemonSqueezyWebhookController::class, 'handle']);

// Public triage share page — no auth, token scoped to 24h TTL
Route::get('/s/{token}', \App\Http\Controllers\Web\TriageSharePageController::class)
    ->name('triage.share')
    ->middleware('throttle:30,1');

Route::prefix('console')->name('console.')->group(function () {
    // Auth (guest only)
    Route::middleware('guest')->group(function () {
        Route::get('/login', [\App\Http\Controllers\Console\AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [\App\Http\Controllers\Console\AuthController::class, 'login'])->middleware('throttle:5,1', 'throttle:login-by-email');
    });

    Route::post('/logout', [\App\Http\Controllers\Console\AuthController::class, 'logout'])
        ->name('logout')
        ->middleware('auth');

    // Suspended account page (public — user is logged out before redirect)
    Route::get('/suspended', fn () => inertia('Console/Suspended'))->name('suspended');

    // Slack OAuth callback — intentionally public: Slack redirects here from a different
    // domain (ngrok / production), so no session cookie is present. Auth context is
    // carried in the encrypted `state` parameter instead.
    Route::get('/slack/callback', [\App\Http\Controllers\Console\SlackOAuthController::class, 'callback'])
        ->name('slack.callback');

    // OAuth popup close page — public, same-origin as the opener (ticketlens.test).
    // The callback (on ngrok) redirects here so window.close() works cross-origin.
    Route::get('/oauth-close', function () {
        $integration = request()->string('integration')->value();
        $status      = request()->string('status')->value();
        $message     = request()->string('message')->value();
        return response()->view('oauth-popup', [
            'success'     => $status === 'success',
            'integration' => $integration,
            'message'     => $message ?: null,
        ]);
    })->name('oauth.close');

    // Console root → redirect to dashboard
    Route::get('/', fn () => redirect()->route('console.dashboard'))->name('index');

    // CLI browser login — user must be authenticated to grant CLI access
    Route::middleware('auth')->group(function () {
        Route::get('/auth/cli',  [\App\Http\Controllers\Console\CliAuthController::class, 'show'])->name('auth.cli.show');
        Route::post('/auth/cli', [\App\Http\Controllers\Console\CliAuthController::class, 'authorize'])->name('auth.cli.authorize');
    });

    // Authenticated console routes
    Route::middleware('auth')->group(function () {
        // Stop impersonation — lives OUTSIDE the `owner` sub-group because during
        // impersonation the session is authed as the target (non-owner). The controller
        // checks the `impersonator_id` session key to authorise.
        Route::delete('/impersonate', [\App\Http\Controllers\Owner\ImpersonationController::class, 'destroy'])
            ->name('impersonate.stop');

        // Dashboard — landing page after login
        Route::get('/dashboard', [\App\Http\Controllers\Console\DashboardController::class, 'index'])->name('dashboard');

        // Analytics — accessible to all authenticated users (Free shows teaser)
        Route::get('/analytics', [\App\Http\Controllers\Console\AnalyticsController::class, 'index'])->name('analytics');

        // Account — accessible to all authenticated users
        Route::get('/account', [\App\Http\Controllers\Console\AccountController::class, 'index'])->name('account');
        Route::post('/account/cli-token', [\App\Http\Controllers\Console\AccountController::class, 'generateCliToken'])->name('account.cli-token.generate');
        Route::delete('/account/cli-token', [\App\Http\Controllers\Console\AccountController::class, 'revokeCliToken'])->name('account.cli-token.revoke');

        // Connections — tracker profile management, all tiers
        $connCtrl = \App\Http\Controllers\Console\ConnectionsController::class;
        Route::get('/connections',                     [$connCtrl, 'index'])->name('connections');
        Route::post('/connections',                    [$connCtrl, 'store'])->name('connections.store');
        Route::put('/connections/{trackerProfile}',    [$connCtrl, 'update'])->name('connections.update');
        Route::delete('/connections/{trackerProfile}', [$connCtrl, 'destroy'])->name('connections.destroy');

        // Upgrade page — shown when permission is denied
        Route::get('/upgrade', [\App\Http\Controllers\Console\UpgradeController::class, 'index'])->name('upgrade');

        // Workflow modules (permission-gated)
        Route::middleware('permission:Schedules')->group(function () {
            $ctrl = \App\Http\Controllers\Console\SchedulesController::class;
            Route::get('/schedules',                    [$ctrl, 'index'])->name('schedules');
            Route::post('/schedules',                   [$ctrl, 'store']);
            Route::patch('/schedules/{schedule}',       [$ctrl, 'update']);
            Route::patch('/schedules/{schedule}/toggle',[$ctrl, 'toggle']);
            Route::delete('/schedules/{schedule}',      [$ctrl, 'destroy']);
        });
        Route::get('/digest-history', [\App\Http\Controllers\Console\DigestsController::class, 'index'])
            ->middleware('permission:Digests')->name('digest-history');
        Route::get('/summarize', [\App\Http\Controllers\Console\SummarizeController::class, 'index'])
            ->middleware('permission:Summarize')->name('summarize');
        Route::get('/compliance', [\App\Http\Controllers\Console\ComplianceController::class, 'index'])
            ->middleware('permission:Compliance')->name('compliance');
        Route::get('/export', [\App\Http\Controllers\Console\ExportController::class, 'index'])
            ->middleware('permission:Export')->name('export');

        // Dev Attention Queue (Team tier)
        Route::get('/queue', [\App\Http\Controllers\Console\QueueController::class, 'index'])
            ->middleware('permission:AttentionQueue')->name('queue');

        // Team management
        Route::get('/team', [\App\Http\Controllers\Console\TeamController::class, 'index'])
            ->middleware('permission:MultiAccount')->name('team');

        // Admin — team-lead routes (leads + managers)
        Route::prefix('admin')->name('admin.')->middleware('team.lead')->group(function () {
            Route::get('/team-health',            [\App\Http\Controllers\Console\Admin\TeamHealthController::class,            'index'])->name('team-health');
            Route::get('/stats',                  [\App\Http\Controllers\Console\Admin\StatsController::class,                  'index'])->name('stats');
            Route::get('/compliance-analytics',   [\App\Http\Controllers\Console\Admin\ComplianceAnalyticsController::class,   'index'])->name('compliance-analytics');
        });

        // AI providers — any user with Summarize permission (Pro tier, owner grant, or owner god-mode)
        Route::prefix('admin')->name('admin.')->middleware('permission:Summarize')->group(function () {
            Route::get('/ai',                      [\App\Http\Controllers\Console\Admin\AiController::class, 'index'])->name('ai');
            Route::get('/ai-providers',            [\App\Http\Controllers\Api\AiProviderController::class, 'index'])->name('ai-providers.index');
            Route::post('/ai-providers',           [\App\Http\Controllers\Api\AiProviderController::class, 'store'])->name('ai-providers.store');
            Route::put('/ai-providers/{id}',       [\App\Http\Controllers\Api\AiProviderController::class, 'update'])->name('ai-providers.update');
            Route::delete('/ai-providers/{id}',    [\App\Http\Controllers\Api\AiProviderController::class, 'destroy'])->name('ai-providers.destroy');
            Route::post('/ai-providers/{id}/test', [\App\Http\Controllers\Api\AiProviderController::class, 'test'])->name('ai-providers.test')->middleware('throttle:ai-test');
        });

        // Admin — manager-only routes
        Route::prefix('admin')->name('admin.')->middleware('team.manager')->group(function () {
            Route::get('/members',                  [\App\Http\Controllers\Console\Admin\MembersController::class, 'index'])->name('members.index');
            Route::post('/members',                 [\App\Http\Controllers\Console\Admin\MembersController::class, 'store'])->name('members.store');
            Route::delete('/members/{user}',        [\App\Http\Controllers\Console\Admin\MembersController::class, 'destroy'])->name('members.destroy');
            Route::post('/members/{user}/promote',  [\App\Http\Controllers\Console\Admin\MembersController::class, 'promote'])->name('members.promote');
            Route::post('/members/{user}/role',     [\App\Http\Controllers\Console\Admin\MembersController::class, 'assignRole'])->name('members.role');
            Route::get('/seats',                    [\App\Http\Controllers\Console\Admin\SeatsController::class, 'index'])->name('seats.index');
            Route::get('/process-metrics',          [\App\Http\Controllers\Console\Admin\ProcessMetricsController::class, 'index'])->name('process-metrics');
            Route::get('/integrations',             [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'index'])->name('integrations');
            Route::get('/integrations/channels',    [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'channels'])->name('integrations.channels');
            Route::post('/integrations/channel',    [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'saveChannel'])->name('integrations.channel');
            Route::post('/integrations/test',       [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'sendTest'])->name('integrations.test');
            Route::delete('/integrations',          [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'disconnect'])->name('integrations.disconnect');
            Route::get('/alerts',                         [\App\Http\Controllers\Console\Admin\AlertsController::class, 'index'])->name('alerts');
            Route::patch('/alerts/needs-response',        [\App\Http\Controllers\Console\Admin\AlertsController::class, 'saveNeedsResponse'])->name('alerts.needs-response');
            Route::patch('/alerts/aging',                 [\App\Http\Controllers\Console\Admin\AlertsController::class, 'saveAging'])->name('alerts.aging');
            Route::patch('/alerts/compliance-gap',        [\App\Http\Controllers\Console\Admin\AlertsController::class, 'saveComplianceGap'])->name('alerts.compliance-gap');
            Route::get('/alerts/members',                 [\App\Http\Controllers\Console\Admin\AlertsController::class, 'fetchMembers'])->name('alerts.members');
            Route::get('/alerts/channels',                [\App\Http\Controllers\Console\Admin\AlertsController::class, 'fetchChannels'])->name('alerts.channels');
            Route::patch('/alerts/channel',               [\App\Http\Controllers\Console\Admin\AlertsController::class, 'saveChannelAlert'])->name('alerts.channel');
            Route::post('/alerts/{alertType}/test',       [\App\Http\Controllers\Console\Admin\AlertsController::class, 'testAlert'])->name('alerts.type.test')->where('alertType', 'needs-response|aging|compliance-gap');
            Route::post('/alerts/rules',                                        [\App\Http\Controllers\Console\Admin\AlertsController::class, 'storeRule'])->name('alerts.rules.store');
            Route::patch('/alerts/rules/{rule}',                                [\App\Http\Controllers\Console\Admin\AlertsController::class, 'toggleRule'])->name('alerts.rules.toggle');
            Route::delete('/alerts/rules/{rule}',                               [\App\Http\Controllers\Console\Admin\AlertsController::class, 'destroyRule'])->name('alerts.rules.destroy');
            Route::post('/alerts/rules/{rule}/test',                            [\App\Http\Controllers\Console\Admin\AlertsController::class, 'testRule'])->name('alerts.rules.test');
            Route::post('/alerts/digest-schedules',                             [\App\Http\Controllers\Console\Admin\AlertsController::class, 'storeDigestSchedule'])->name('alerts.digest-schedules.store');
            Route::patch('/alerts/digest-schedules/{digestSchedule}',           [\App\Http\Controllers\Console\Admin\AlertsController::class, 'toggleDigestSchedule'])->name('alerts.digest-schedules.toggle');
            Route::delete('/alerts/digest-schedules/{digestSchedule}',          [\App\Http\Controllers\Console\Admin\AlertsController::class, 'destroyDigestSchedule'])->name('alerts.digest-schedules.destroy');
            Route::post('/alerts/digest-schedules/{digestSchedule}/test',       [\App\Http\Controllers\Console\Admin\AlertsController::class, 'testDigestSchedule'])->name('alerts.digest-schedules.test');
            Route::get('/digests',                                               [\App\Http\Controllers\Console\Admin\DigestsController::class, 'index'])->name('digests');

            // Workflow Rules — manager-only (also tier-gated in controller for Pro+ enforcement)
            Route::get('/rules',                 [\App\Http\Controllers\Console\Admin\RulesController::class, 'index'])->name('rules.index');
            Route::post('/rules/stale',          [\App\Http\Controllers\Console\Admin\RulesController::class, 'saveStale'])->name('rules.stale.save');
            Route::patch('/rules/stale/toggle',  [\App\Http\Controllers\Console\Admin\RulesController::class, 'toggleStale'])->name('rules.stale.toggle');
            Route::delete('/rules/stale',        [\App\Http\Controllers\Console\Admin\RulesController::class, 'destroyStale'])->name('rules.stale.destroy');
        });

        // Brief templates — read for all auth users; mutations require team.manager (owner bypasses)
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/templates', [\App\Http\Controllers\Console\Admin\BriefTemplatesController::class, 'index'])->name('templates.index');
        });
        Route::prefix('admin')->name('admin.')->middleware('team.manager')->group(function () {
            Route::post('/templates',                   [\App\Http\Controllers\Console\Admin\BriefTemplatesController::class, 'store'])->name('templates.store');
            Route::put('/templates/{briefTemplate}',    [\App\Http\Controllers\Console\Admin\BriefTemplatesController::class, 'update'])->name('templates.update');
            Route::delete('/templates/{briefTemplate}', [\App\Http\Controllers\Console\Admin\BriefTemplatesController::class, 'destroy'])->name('templates.destroy');
        });

        // Slack OAuth redirect — requires auth to know who's initiating the flow
        Route::get('/slack/redirect', [\App\Http\Controllers\Console\SlackOAuthController::class, 'redirect'])
            ->name('slack.redirect');

        // Owner-only panel
        Route::prefix('owner')->name('owner.')->middleware('owner')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Owner\DashboardController::class, 'index'])->name('dashboard');

            // Client management — /create must be before {user} to avoid model-binding collision
            Route::get('/clients',        [\App\Http\Controllers\Owner\ClientController::class, 'index'])->name('clients.index');
            Route::get('/clients/create', [\App\Http\Controllers\Owner\ClientController::class, 'create'])->name('clients.create');
            Route::post('/clients',       [\App\Http\Controllers\Owner\ClientController::class, 'store'])->name('clients.store');
            Route::get('/clients/{user}', [\App\Http\Controllers\Owner\ClientController::class, 'show'])->name('clients.show');
            Route::patch('/clients/{user}', [\App\Http\Controllers\Owner\ClientController::class, 'update'])->name('clients.update');
            Route::post('/clients/{user}/suspend', [\App\Http\Controllers\Owner\ClientController::class, 'suspend'])->name('clients.suspend');
            Route::post('/clients/{user}/restore', [\App\Http\Controllers\Owner\ClientController::class, 'restore'])->name('clients.restore');
            Route::delete('/clients/{user}', [\App\Http\Controllers\Owner\ClientController::class, 'destroy'])->name('clients.destroy');

            // Team management
            Route::get('/teams',                                    [\App\Http\Controllers\Owner\TeamController::class, 'index'])->name('teams.index');
            Route::get('/teams/{group}',                            [\App\Http\Controllers\Owner\TeamController::class, 'show'])->name('teams.show');
            Route::delete('/teams/{group}/members/{user}',          [\App\Http\Controllers\Owner\TeamController::class, 'removeMember'])->name('teams.members.destroy');

            // Platform insights — usage trends, popular commands, ROI per account
            Route::get('/insights', [\App\Http\Controllers\Owner\InsightsController::class, 'index'])->name('insights');

            // Revenue dashboard (MRR, tier breakdown, recent license events)
            Route::get('/revenue', [\App\Http\Controllers\Owner\RevenueController::class, 'index'])->name('revenue');

            // License issuance — Owner generates keys directly + optionally emails
            Route::get('/licenses',                 [\App\Http\Controllers\Owner\LicenseController::class, 'index'])->name('licenses.index');
            Route::get('/licenses/create',          [\App\Http\Controllers\Owner\LicenseController::class, 'create'])->name('licenses.create');
            Route::post('/licenses',                [\App\Http\Controllers\Owner\LicenseController::class, 'store'])->name('licenses.store');
            Route::get('/licenses/{license}/created', [\App\Http\Controllers\Owner\LicenseController::class, 'created'])->name('licenses.created');
            Route::patch('/licenses/{license}',     [\App\Http\Controllers\Owner\LicenseController::class, 'update'])->name('licenses.update');
            Route::delete('/licenses/{license}',    [\App\Http\Controllers\Owner\LicenseController::class, 'destroy'])->name('licenses.destroy');

            // Audit log
            Route::get('/audit', [\App\Http\Controllers\Owner\AuditController::class, 'index'])->name('audit.index');

            // Tier→feature matrix
            Route::get('/tiers', [\App\Http\Controllers\Owner\TierController::class, 'index'])->name('tiers.index');
            Route::post('/tiers/{tier}/features', [\App\Http\Controllers\Owner\TierController::class, 'addFeature'])->name('tiers.features.add');
            Route::delete('/tiers/{tier}/features/{feature}', [\App\Http\Controllers\Owner\TierController::class, 'removeFeature'])->name('tiers.features.remove');

            // Feature grants
            Route::post('/clients/{user}/grants', [\App\Http\Controllers\Owner\GrantController::class, 'store'])->name('grants.store');
            Route::delete('/clients/{user}/grants/{grant}', [\App\Http\Controllers\Owner\GrantController::class, 'destroy'])->name('grants.destroy');

            // Impersonation — start only (stop lives outside this group, see above)
            Route::post('/impersonate/{user}', [\App\Http\Controllers\Owner\ImpersonationController::class, 'store'])->name('impersonate.start');

            // AI Settings — owner has their own provider keys like any user
            Route::get('/ai', [\App\Http\Controllers\Console\Admin\AiController::class, 'index'])->name('ai');
            // Integrations — owner manages Slack on behalf of any group via ?group_id=X
            Route::get('/integrations',          [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'index'])->name('integrations');
            Route::get('/integrations/channels', [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'channels'])->name('integrations.channels');
            Route::post('/integrations/channel', [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'saveChannel'])->name('integrations.channel');
            Route::post('/integrations/test',    [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'sendTest'])->name('integrations.test');
            Route::delete('/integrations',       [\App\Http\Controllers\Console\Admin\IntegrationsController::class, 'disconnect'])->name('integrations.disconnect');
            // Alert settings — owner manages on behalf of any group via ?group_id=X
            Route::get('/alerts',                         [\App\Http\Controllers\Console\Admin\AlertsController::class, 'index'])->name('alerts');
            Route::patch('/alerts/needs-response',        [\App\Http\Controllers\Console\Admin\AlertsController::class, 'saveNeedsResponse'])->name('alerts.needs-response');
            Route::patch('/alerts/aging',                 [\App\Http\Controllers\Console\Admin\AlertsController::class, 'saveAging'])->name('alerts.aging');
            Route::patch('/alerts/compliance-gap',        [\App\Http\Controllers\Console\Admin\AlertsController::class, 'saveComplianceGap'])->name('alerts.compliance-gap');
            Route::get('/alerts/members',                 [\App\Http\Controllers\Console\Admin\AlertsController::class, 'fetchMembers'])->name('alerts.members');
            Route::get('/alerts/channels',                [\App\Http\Controllers\Console\Admin\AlertsController::class, 'fetchChannels'])->name('alerts.channels');
            Route::patch('/alerts/channel',               [\App\Http\Controllers\Console\Admin\AlertsController::class, 'saveChannelAlert'])->name('alerts.channel');
            Route::post('/alerts/{alertType}/test',       [\App\Http\Controllers\Console\Admin\AlertsController::class, 'testAlert'])->name('alerts.type.test')->where('alertType', 'needs-response|aging|compliance-gap');
            Route::post('/alerts/rules',                                        [\App\Http\Controllers\Console\Admin\AlertsController::class, 'storeRule'])->name('alerts.rules.store');
            Route::patch('/alerts/rules/{rule}',                                [\App\Http\Controllers\Console\Admin\AlertsController::class, 'toggleRule'])->name('alerts.rules.toggle');
            Route::delete('/alerts/rules/{rule}',                               [\App\Http\Controllers\Console\Admin\AlertsController::class, 'destroyRule'])->name('alerts.rules.destroy');
            Route::post('/alerts/rules/{rule}/test',                            [\App\Http\Controllers\Console\Admin\AlertsController::class, 'testRule'])->name('alerts.rules.test');
            Route::post('/alerts/digest-schedules',                             [\App\Http\Controllers\Console\Admin\AlertsController::class, 'storeDigestSchedule'])->name('alerts.digest-schedules.store');
            Route::patch('/alerts/digest-schedules/{digestSchedule}',           [\App\Http\Controllers\Console\Admin\AlertsController::class, 'toggleDigestSchedule'])->name('alerts.digest-schedules.toggle');
            Route::delete('/alerts/digest-schedules/{digestSchedule}',          [\App\Http\Controllers\Console\Admin\AlertsController::class, 'destroyDigestSchedule'])->name('alerts.digest-schedules.destroy');
            Route::post('/alerts/digest-schedules/{digestSchedule}/test',       [\App\Http\Controllers\Console\Admin\AlertsController::class, 'testDigestSchedule'])->name('alerts.digest-schedules.test');
            Route::get('/digests',                                               [\App\Http\Controllers\Console\Admin\DigestsController::class, 'index'])->name('digests');
        });
    });
});
