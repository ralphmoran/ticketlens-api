<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TierPricesConfigTest extends TestCase
{
    private function prices(): array
    {
        return (require dirname(__DIR__, 2) . '/config/tiers.php')['prices'];
    }

    public function test_pro_monthly_price_is_nine_dollars(): void
    {
        $this->assertSame(9, $this->prices()['pro']);
    }

    public function test_team_monthly_seat_price_is_nineteen_dollars(): void
    {
        $this->assertSame(19, $this->prices()['team']);
    }

    public function test_free_enterprise_and_owner_stay_unpriced(): void
    {
        $prices = $this->prices();

        $this->assertSame(0, $prices['free']);
        $this->assertSame(0, $prices['enterprise']);
        $this->assertSame(0, $prices['owner']);
    }
}
