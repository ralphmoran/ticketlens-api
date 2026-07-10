<?php

namespace Tests\Unit\Jobs;

use App\Jobs\WarmNpmDownloadsCacheJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WarmNpmDownloadsCacheJobTest extends TestCase
{
    public function test_warms_cache_for_all_allowed_periods_on_success(): void
    {
        Http::fake([
            'api.npmjs.org/*' => Http::response(['downloads' => 999], 200),
        ]);

        (new WarmNpmDownloadsCacheJob())->handle();

        foreach (config('ticketlens.client_health_periods') as $period) {
            $this->assertSame(999, Cache::get("npm_downloads_{$period}"));
        }
    }

    public function test_leaves_existing_cache_value_untouched_on_non_ok_response(): void
    {
        Cache::put('npm_downloads_30', 4242, 86400);
        Http::fake([
            'api.npmjs.org/*' => Http::response([], 500),
        ]);

        (new WarmNpmDownloadsCacheJob())->handle();

        $this->assertSame(4242, Cache::get('npm_downloads_30'));
    }

    public function test_leaves_existing_cache_value_untouched_when_request_throws(): void
    {
        Cache::put('npm_downloads_30', 4242, 86400);
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        (new WarmNpmDownloadsCacheJob())->handle();

        $this->assertSame(4242, Cache::get('npm_downloads_30'));
    }

    public function test_logs_a_warning_on_non_ok_response(): void
    {
        Http::fake([
            'api.npmjs.org/*' => Http::response([], 500),
        ]);
        Log::spy();

        (new WarmNpmDownloadsCacheJob())->handle();

        Log::shouldHaveReceived('warning')
            ->with('npm downloads fetch returned a non-OK response', \Mockery::on(
                fn ($context) => $context['status'] === 500
            ))
            ->atLeast()->once();
    }

    public function test_logs_a_warning_when_request_throws(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });
        Log::spy();

        (new WarmNpmDownloadsCacheJob())->handle();

        Log::shouldHaveReceived('warning')
            ->with('npm downloads fetch threw', \Mockery::on(
                fn ($context) => $context['message'] === 'Connection timed out'
            ))
            ->atLeast()->once();
    }
}
