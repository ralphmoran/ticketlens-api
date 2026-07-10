<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WarmNpmDownloadsCacheJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        foreach (config('ticketlens.client_health_periods') as $period) {
            $end   = now()->format('Y-m-d');
            $start = now()->subDays($period)->format('Y-m-d');

            try {
                $response = Http::timeout(10)->get(
                    "https://api.npmjs.org/downloads/point/{$start}:{$end}/ticketlens"
                );

                if ($response->ok()) {
                    Cache::put("npm_downloads_{$period}", (int) ($response->json('downloads') ?? 0), 86400);
                    continue;
                }

                // Leave the last-good cached value in place — a transient failure
                // must not blank out a working npm_downloads figure for 24h.
                Log::warning('npm downloads fetch returned a non-OK response', [
                    'period' => $period,
                    'status' => $response->status(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('npm downloads fetch threw', [
                    'period' => $period,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
