<?php

namespace Tests\Unit\Enums;

use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests — lock the permission bitmask values.
 *
 * These tests document the exact integer values of every permission bit
 * and every tier composite. ANY change here must be intentional and
 * accompanied by a data migration.
 *
 * DO NOT update these values to make tests pass — update the migration
 * and the enum together, and update these locks last.
 */
class PermissionBitmaskLockTest extends TestCase
{
    // --- Tier composites ---

    public function test_free_tier_equals_64(): void
    {
        $this->assertSame(64, Permission::free());
    }

    public function test_pro_tier_equals_71(): void
    {
        // 64 (SavingsAnalytics) | 4 (Summarize) | 2 (Digests) | 1 (Schedules) = 71
        $this->assertSame(71, Permission::pro());
    }

    public function test_team_tier_equals_127(): void
    {
        // pro (71) | 8 (Compliance) | 16 (Export) | 32 (MultiAccount) = 127
        $this->assertSame(127, Permission::team());
    }

    public function test_enterprise_tier_equals_team(): void
    {
        $this->assertSame(Permission::team(), Permission::enterprise());
    }

    // --- Individual bit values ---

    public function test_schedules_is_bit_0(): void
    {
        $this->assertSame(1, Permission::Schedules->value);
    }

    public function test_digests_is_bit_1(): void
    {
        $this->assertSame(2, Permission::Digests->value);
    }

    public function test_summarize_is_bit_2(): void
    {
        $this->assertSame(4, Permission::Summarize->value);
    }

    public function test_compliance_is_bit_3(): void
    {
        $this->assertSame(8, Permission::Compliance->value);
    }

    public function test_export_is_bit_4(): void
    {
        $this->assertSame(16, Permission::Export->value);
    }

    public function test_multi_account_is_bit_5(): void
    {
        $this->assertSame(32, Permission::MultiAccount->value);
    }

    public function test_savings_analytics_is_bit_6(): void
    {
        $this->assertSame(64, Permission::SavingsAnalytics->value);
    }

    public function test_team_manage_members_is_bit_7(): void
    {
        // Renamed from AdminUsers — bit VALUE unchanged at 128 (stability invariant).
        $this->assertSame(128, Permission::TeamManageMembers->value);
    }

    public function test_team_manage_seats_is_bit_8(): void
    {
        // Renamed from AdminLicenses — bit VALUE unchanged at 256 (stability invariant).
        $this->assertSame(256, Permission::TeamManageSeats->value);
    }

    public function test_team_manager_mask_is_384(): void
    {
        $this->assertSame(384, Permission::teamManagerMask());
    }
}
