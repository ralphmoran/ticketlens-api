<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TierPricesConfigTest extends TestCase
{
    private const PAID_TIERS = ['pro', 'team'];

    private function prices(): array
    {
        return (require dirname(__DIR__, 2) . '/config/tiers.php')['prices'];
    }

    public function test_every_paid_tier_has_a_positive_integer_price(): void
    {
        foreach (self::PAID_TIERS as $tier) {
            $this->assertIsInt($this->prices()[$tier], "$tier price must be an integer");
            $this->assertGreaterThan(0, $this->prices()[$tier], "$tier must be priced");
        }
    }

    public function test_free_enterprise_and_owner_stay_unpriced(): void
    {
        $prices = $this->prices();

        $this->assertSame(0, $prices['free']);
        $this->assertSame(0, $prices['enterprise']);
        $this->assertSame(0, $prices['owner']);
    }
}
