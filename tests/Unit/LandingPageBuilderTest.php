<?php

namespace Tests\Unit;

use App\Services\LandingPageBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LandingPageBuilderTest extends TestCase
{
    private function render(string $template, int $pro = 10, int $team = 20, int $discount = 20): string
    {
        return (new LandingPageBuilder())->render($template, ['pro' => $pro, 'team' => $team], $discount);
    }

    public function test_monthly_prices_are_injected(): void
    {
        $this->assertSame('10 / 20', $this->render('{{pro_monthly}} / {{team_monthly}}'));
    }

    public function test_annual_per_month_applies_the_discount(): void
    {
        $this->assertSame('8 / 16', $this->render('{{pro_annual_per_month}} / {{team_annual_per_month}}'));
    }

    public function test_annual_per_month_keeps_cents_when_not_whole(): void
    {
        $this->assertSame('7.20', $this->render('{{pro_annual_per_month}}', pro: 9));
    }

    public function test_annual_total_is_twelve_discounted_months_rounded(): void
    {
        $this->assertSame('86 / 182', $this->render('{{pro_annual_total}} / {{team_annual_total}}', pro: 9, team: 19));
    }

    public function test_discount_percent_is_injected(): void
    {
        $this->assertSame('~25% off', $this->render('~{{annual_discount}}% off', discount: 25));
    }

    public function test_unknown_placeholder_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->render('{{enterprise_monthly}}');
    }

    public function test_template_without_placeholders_is_returned_unchanged(): void
    {
        $this->assertSame('<p>static</p>', $this->render('<p>static</p>'));
    }
}
