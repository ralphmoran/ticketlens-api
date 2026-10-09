<?php

namespace Tests\Feature;

use App\Services\LandingPageBuilder;
use Tests\TestCase;

class LandingPageBuildTest extends TestCase
{
    private function freshRender(): string
    {
        return app(LandingPageBuilder::class)
            ->renderFromConfig(file_get_contents(resource_path('landing/index.html')));
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

    public function test_landing_enterprise_cta_uses_the_configured_contact_email(): void
    {
        $this->assertStringContainsString(
            'mailto:' . config('tiers.enterprise_contact_email'),
            $this->freshRender(),
        );
    }

    public function test_enterprise_contact_email_has_a_non_production_default(): void
    {
        $tiers = require config_path('tiers.php');

        $this->assertArrayHasKey('enterprise_contact_email', $tiers);
        $this->assertNotEmpty($tiers['enterprise_contact_email']);
    }

    public function test_free_card_lists_the_ungated_cli_features(): void
    {
        $free = explode('<div class="pricing-tier">Pro</div>', $this->freshRender())[0];

        foreach (['install-hooks', 'ticketlens pr', 'ticketlens standup'] as $feature) {
            $this->assertStringContainsString($feature, $free, "$feature is ungated in the CLI and belongs on the Free card");
        }
    }

    public function test_pro_card_lists_only_the_gated_pr_features(): void
    {
        $afterPro = explode('<div class="pricing-tier">Pro</div>', $this->freshRender())[1];
        $pro      = explode('<div class="pricing-tier">Team</div>', $afterPro)[0];

        $this->assertStringContainsString('pr --open', $pro);
        $this->assertStringNotContainsString('install-hooks', $pro);
    }

    public function test_landing_privacy_copy_makes_no_unqualified_server_claims(): void
    {
        $html = $this->freshRender();

        $this->assertStringNotContainsString('never relays it through our servers', $html);
        $this->assertStringContainsString('Optional cloud features', $html);
    }

    public function test_landing_build_refuses_a_test_domain_address_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $out = tempnam(sys_get_temp_dir(), 'landing');
        config(['tiers.enterprise_contact_email' => 'enterprise@ticketlens.test']);

        $this->artisan('landing:build', ['--out' => $out])->assertExitCode(1);

        unlink($out);
    }

    public function test_root_route_serves_the_landing_page(): void
    {
        $this->get('/')->assertOk();
    }
}
