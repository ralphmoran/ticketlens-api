<?php

namespace Tests\Feature\Permissions;

use App\Enums\Permission;
use Tests\TestCase;

class PermissionBitmaskTest extends TestCase
{
    // Lock tests — these values must never silently change.
    // If any of these fail, a Permission enum edit broke an invariant.

    public function test_individual_bits_are_powers_of_two(): void
    {
        $this->assertSame(1,   Permission::Schedules->value);
        $this->assertSame(2,   Permission::Digests->value);
        $this->assertSame(4,   Permission::Summarize->value);
        $this->assertSame(8,   Permission::Compliance->value);
        $this->assertSame(16,  Permission::Export->value);
        $this->assertSame(32,  Permission::MultiAccount->value);
        $this->assertSame(64,  Permission::SavingsAnalytics->value);
        $this->assertSame(128, Permission::TeamManageMembers->value);
        $this->assertSame(256, Permission::TeamManageSeats->value);
    }

    public function test_free_bitmask_is_64(): void
    {
        $this->assertSame(64, Permission::free());
    }

    public function test_pro_bitmask_is_2119(): void
    {
        // 64 | 4 | 2 | 1 | 2048 (WorkflowRules) = 2119
        $this->assertSame(2119, Permission::pro());
    }

    public function test_team_manager_mask_is_384(): void
    {
        $this->assertSame(384, Permission::teamManagerMask());
    }

    // AttentionQueue (512) will be added — these tests verify the existing
    // presets are unchanged by the addition.
    public function test_team_bitmask_includes_attention_queue(): void
    {
        $this->assertSame(512, Permission::AttentionQueue->value);
        $this->assertTrue((Permission::team() & Permission::AttentionQueue->value) !== 0);
    }

    public function test_enterprise_bitmask_matches_team(): void
    {
        $this->assertSame(Permission::team(), Permission::enterprise());
    }

    public function test_free_does_not_include_attention_queue(): void
    {
        $this->assertSame(0, Permission::free() & Permission::AttentionQueue->value);
    }

    public function test_pro_does_not_include_attention_queue(): void
    {
        $this->assertSame(0, Permission::pro() & Permission::AttentionQueue->value);
    }

    public function test_pro_includes_workflow_rules(): void
    {
        $this->assertSame(2048, Permission::pro() & Permission::WorkflowRules->value);
    }

    public function test_team_includes_workflow_rules(): void
    {
        $this->assertSame(2048, Permission::team() & Permission::WorkflowRules->value);
    }
}
