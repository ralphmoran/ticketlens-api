<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Feature;
use App\Models\Group;
use App\Models\RecallSettings;
use App\Models\User;
use App\Models\UserFeatureGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecallSettingsUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function grantRecall(User $user, User $grantedBy): void
    {
        $feature = Feature::firstOrCreate(['name' => 'recall'], ['bit_value' => 4096, 'label' => 'Recall', 'sort_order' => 100]);
        UserFeatureGrant::create(['user_id' => $user->id, 'feature_id' => $feature->id, 'granted_by' => $grantedBy->id]);
    }

    // team(2687) | teamManagerMask(384) = 3071 — must include TeamManageMembers(128) for EnsureTeamManager
    private function makeManager(): array
    {
        $owner   = User::factory()->create(['is_owner' => true]);
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 3071]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        $this->grantRecall($manager, $owner);
        return [$manager, $group, $owner];
    }

    private function makeEntitledMember(Group $group, User $grantedBy): User
    {
        $member = User::factory()->create(['tier' => 'pro']);
        $group->members()->attach($member->id);
        $this->grantRecall($member, $grantedBy);
        return $member;
    }

    private array $validPayload = [
        'flush_cooldown_ms' => 300_000,
        'timeout_ms'        => 8_000,
        'max_queue_size'    => 50,
        'max_entry_age_ms'  => 604_800_000,
    ];

    public function test_manager_can_set_a_settings_override_for_their_own_group(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', $this->validPayload)->assertRedirect();

        $this->assertDatabaseHas('recall_settings', ['group_id' => $group->id, ...$this->validPayload]);
    }

    public function test_updating_again_upserts_rather_than_duplicating_the_row(): void
    {
        [$manager, $group] = $this->makeManager();
        $this->actingAs($manager)->put('/console/admin/recall/settings', $this->validPayload);

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            'flush_cooldown_ms' => 120_000, 'timeout_ms' => 5_000, 'max_queue_size' => 30, 'max_entry_age_ms' => 86_400_000,
        ]);

        $this->assertSame(1, RecallSettings::where('group_id', $group->id)->count());
        $this->assertDatabaseHas('recall_settings', ['group_id' => $group->id, 'flush_cooldown_ms' => 120_000]);
    }

    public function test_blocks_a_non_manager_even_if_recall_entitled(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $member = $this->makeEntitledMember($group, $owner);

        $this->actingAs($member)->put('/console/admin/recall/settings', $this->validPayload)
            ->assertRedirect('/console/dashboard');
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_owner_without_a_group_id_param_gets_403_not_a_silent_no_op(): void
    {
        [, $group, $owner] = $this->makeManager();

        $this->actingAs($owner)->put('/console/admin/recall/settings', $this->validPayload)->assertStatus(403);
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_owner_can_set_settings_for_a_group_they_do_not_personally_own(): void
    {
        [, $group, $owner] = $this->makeManager();

        $this->actingAs($owner)->put("/console/admin/recall/settings?group_id={$group->id}", $this->validPayload)
            ->assertRedirect();
        $this->assertDatabaseHas('recall_settings', ['group_id' => $group->id, ...$this->validPayload]);
    }

    // ── Validation bounds — Pre-Code Audit requirement: a manager cannot ────
    // ── configure a value that would hammer the shared backend or hang the CLI ─

    public function test_a_cooldown_below_the_1_minute_floor_is_rejected(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'flush_cooldown_ms' => 59_999,
        ])->assertSessionHasErrors('flush_cooldown_ms');
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_a_cooldown_above_the_24_hour_ceiling_is_rejected(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'flush_cooldown_ms' => 86_400_001,
        ])->assertSessionHasErrors('flush_cooldown_ms');
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_a_timeout_above_the_30_second_ceiling_is_rejected(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'timeout_ms' => 30_001,
        ])->assertSessionHasErrors('timeout_ms');
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_a_max_queue_size_below_the_floor_is_rejected(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'max_queue_size' => 9,
        ])->assertSessionHasErrors('max_queue_size');
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_a_non_integer_value_is_rejected(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'timeout_ms' => 'not-a-number',
        ])->assertSessionHasErrors('timeout_ms');
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_writes_an_audit_log_with_old_and_new_values(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', $this->validPayload);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $manager->id,
            'action'   => 'recall.settings_updated',
        ]);
        $log = \App\Models\AuditLog::where('action', 'recall.settings_updated')->first();
        $this->assertSame(RecallSettings::DEFAULTS['flush_cooldown_ms'], $log->old_value['flush_cooldown_ms']);
        $this->assertSame(300_000, $log->new_value['flush_cooldown_ms']);
    }
}
