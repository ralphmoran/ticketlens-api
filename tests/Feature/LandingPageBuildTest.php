<?php

namespace Tests\Feature;

use App\Services\LandingPageBuilder;
use Tests\TestCase;

class LandingPageBuildTest extends TestCase
{
    private function freshRender(): string
    {
        return (new LandingPageBuilder())->render(
            file_get_contents(resource_path('landing/index.html')),
            config('tiers.prices'),
            config('tiers.annual_discount_percent'),
        );
    }

    public function test_committed_landing_page_matches_current_config_prices(): void
    {
        $this->assertSame(
            $this->freshRender(),
            file_get_contents(public_path('landing.html')),
            'public/landing.html is stale. Run: php artisan landing:build',
        );
    }

    public function test_landing_build_command_writes_the_rendered_page(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'landing');

        $this->artisan('landing:build', ['--out' => $out])->assertExitCode(0);

        $this->assertSame($this->freshRender(), file_get_contents($out));
        unlink($out);
    }

    public function test_landing_page_shows_config_prices(): void
    {
        $html = $this->freshRender();

        $this->assertStringContainsString('$' . config('tiers.prices.pro') . '<sub>/mo</sub>', $html);
        $this->assertStringContainsString('$' . config('tiers.prices.team') . '<sub>/seat</sub>', $html);
    }

    public function test_root_route_serves_the_landing_page(): void
    {
        $this->get('/')->assertOk();
    }
}
