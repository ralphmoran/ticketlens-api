<?php

namespace App\Providers;

use App\Models\BriefTemplate;
use App\Models\CustomAlertRule;
use App\Models\SlackDigestSchedule;
use App\Policies\BriefTemplatePolicy;
use App\Policies\CustomAlertRulePolicy;
use App\Policies\DigestSchedulePolicy;
use App\Services\SlackService;
use App\Support\LicenseSkipGuard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SlackService::class, fn () => new SlackService(
            clientId:     (string) config('services.slack.client_id'),
            clientSecret: (string) config('services.slack.client_secret'),
            redirectUri:  (string) config('services.slack.redirect_uri'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LicenseSkipGuard::assertSafe((string) config('app.env'), (bool) config('ticketlens.skip_license'));

        Gate::policy(BriefTemplate::class, BriefTemplatePolicy::class);
        Gate::policy(CustomAlertRule::class, CustomAlertRulePolicy::class);
        Gate::policy(SlackDigestSchedule::class, DigestSchedulePolicy::class);
    }
}
