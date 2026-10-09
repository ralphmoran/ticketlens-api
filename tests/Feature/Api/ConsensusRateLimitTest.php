<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ConsensusRateLimitTest extends TestCase
{
    private function consensusMiddleware(): array
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'v1/consensus' && in_array('POST', $r->methods(), true));

        return $route->gatherMiddleware();
    }

    public function test_consensus_route_is_throttled_by_its_own_limiter(): void
    {
        $this->assertContains('throttle:consensus', $this->consensusMiddleware());
    }

    public function test_consensus_route_does_not_borrow_the_compliance_limiter(): void
    {
        $this->assertNotContains('throttle:compliance', $this->consensusMiddleware());
    }

    public function test_consensus_limit_stays_ten_per_minute(): void
    {
        $limit = app(\Illuminate\Cache\RateLimiter::class)->limiter('consensus')(request());

        $this->assertSame(10, $limit->maxAttempts);
    }
}
