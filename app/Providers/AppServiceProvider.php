<?php

namespace App\Providers;

use App\Services\SlackService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SlackService::class, fn () => new SlackService(
            clientId:     config('services.slack.client_id'),
            clientSecret: config('services.slack.client_secret'),
            redirectUri:  config('services.slack.redirect_uri'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
