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

    public function test_landing_enterprise_band_posts_the_contact_form_to_the_app(): void
    {
        $html = $this->freshRender();

        $this->assertStringContainsString('id="enterprise-form"', $html);
        $this->assertStringContainsString('action="/contact/enterprise"', $html);
        $this->assertStringContainsString('name="website"', $html);
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
        $this->assertStringContainsString('ticketlens review', $pro);
        $this->assertStringNotContainsString('install-hooks', $pro);
    }

    public function test_free_card_says_the_pr_compliance_section_shares_the_monthly_cap(): void
    {
        $free = explode('<div class="pricing-tier">Pro</div>', $this->freshRender())[0];

        $this->assertMatchesRegularExpression('/<li>[^<]*ticketlens pr\)[^<]*3\/month/', $free);
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

    public function test_landing_pro_card_lists_public_api_keys_and_signed_webhooks(): void
    {
        $html = $this->freshRender();

        $this->assertStringContainsString('Public API', $html);
        $this->assertStringContainsString('scoped API keys', $html);
        $this->assertStringContainsString('signed webhooks', $html);
    }

    public function test_landing_team_card_states_the_higher_public_api_rate_limit(): void
    {
        $this->assertStringContainsString('120 requests/min', $this->freshRender());
    }

    public function test_landing_recall_bullet_mentions_autonomous_capture(): void
    {
        $this->assertStringContainsString('captured automatically after a session', $this->freshRender());
    }

    public function test_landing_feature_panel_has_a_public_api_tile_with_a_demo(): void
    {
        $html = $this->freshRender();
        $js = file_get_contents(public_path('assets/app.js'));

        $this->assertStringContainsString('<span class="feat-item-name">Public API</span>', $html);
        $this->assertSame(
            preg_match_all('/class="feat-item( active)?"/', $html),
            $this->featDemoCount($js),
            'Every feat-item tile needs a matching featData demo entry in public/assets/app.js',
        );
    }

    private function featDemoCount(string $js): int
    {
        preg_match('/var featData = \[(.*?)\n    \];/s', $js, $block);

        return substr_count($block[1] ?? '', "label: '");
    }

    public function test_root_route_serves_the_landing_page(): void
    {
        $this->get('/')->assertOk();
    }
}
