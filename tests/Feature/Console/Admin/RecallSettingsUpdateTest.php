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

    // Reuses an existing owner (the platform allows only one) to create a
    // second, independent manager + group — needed for cross-group attack tests.
    private function makeSecondManager(User $owner): array
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 3071]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        $this->grantRecall($manager, $owner);
        return [$manager, $group];
    }

    private array $validPayload = [
        'flush_cooldown_ms' => 300_000,
        'timeout_ms'        => 8_000,
        'max_queue_size'    => 50,
        'max_entry_age_ms'  => 604_800_000,
        'recall_strictness' => 'strict',
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
            'recall_strictness' => 'loose',
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

    // ── Backlog #20: recall_strictness is Console-manageable, mirroring the ──
    // ── existing queue-settings form (enum, not a numeric bound) ─────────────

    public function test_manager_can_set_recall_strictness(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'recall_strictness' => 'loose',
        ])->assertRedirect();

        $this->assertDatabaseHas('recall_settings', ['group_id' => $group->id, 'recall_strictness' => 'loose']);
    }

    public function test_an_unrecognized_recall_strictness_value_is_rejected(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'recall_strictness' => 'aggressive',
        ])->assertSessionHasErrors('recall_strictness');
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_a_missing_recall_strictness_value_is_rejected(): void
    {
        [$manager, $group] = $this->makeManager();
        $payload = $this->validPayload;
        unset($payload['recall_strictness']);

        $this->actingAs($manager)->put('/console/admin/recall/settings', $payload)
            ->assertSessionHasErrors('recall_strictness');
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_recall_strictness_survives_a_full_round_trip_through_index(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'recall_strictness' => 'loose',
        ]);

        $response = $this->actingAs($manager)->get('/console/admin/recall?group_id=' . $group->id);
        $response->assertInertia(fn ($page) => $page
            ->where('settings.values.recall_strictness', 'loose')
            ->where('settings.isOverride', true));
    }

    // ── Red-team pass (Scenario A: Console write path) ──────────────────────

    public function test_attack_non_manager_cannot_set_the_team_default_even_with_a_direct_request(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $member = $this->makeEntitledMember($group, $owner);

        $this->actingAs($member)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'recall_strictness' => 'strict',
        ])->assertRedirect('/console/dashboard');

        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
    }

    public function test_attack_sql_injection_shaped_string_in_recall_strictness_is_rejected_not_executed(): void
    {
        [$manager, $group] = $this->makeManager();

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload, 'recall_strictness' => "strict'); DROP TABLE recall_settings;--",
        ])->assertSessionHasErrors('recall_strictness');

        $this->assertDatabaseMissing('recall_settings', ['group_id' => $group->id]);
        // The table must still exist and be queryable — proves no injection landed.
        $this->assertDatabaseCount('recall_settings', 0);
    }

    public function test_attack_cross_group_idor_a_managers_group_id_param_is_ignored_not_honored(): void
    {
        [$attacker, $attackerGroup, $owner] = $this->makeManager();
        [$victim, $victimGroup] = $this->makeSecondManager($owner);

        // Victim already has their own team default set.
        RecallSettings::create([
            'group_id' => $victimGroup->id, ...$this->validPayload, 'recall_strictness' => 'balanced',
        ]);

        // Attacker (a manager of a DIFFERENT group) tries to overwrite the
        // victim's group settings by passing the victim's group_id.
        $this->actingAs($attacker)->put('/console/admin/recall/settings?group_id=' . $victimGroup->id, [
            ...$this->validPayload, 'recall_strictness' => 'loose',
        ])->assertRedirect();

        // ActiveGroupResolver ignores group_id for non-owners — the write
        // must have landed on the ATTACKER's own group, never the victim's.
        $this->assertDatabaseHas('recall_settings', ['group_id' => $attackerGroup->id, 'recall_strictness' => 'loose']);
        $this->assertDatabaseHas('recall_settings', ['group_id' => $victimGroup->id, 'recall_strictness' => 'balanced']);
    }

    public function test_attack_mass_assignment_extra_fields_are_stripped_by_validation(): void
    {
        [$manager, $group, , ] = $this->makeManager();
        $otherGroupId = Group::create(['name' => 'Someone Elses Team', 'owner_id' => $manager->id])->id;

        $this->actingAs($manager)->put('/console/admin/recall/settings', [
            ...$this->validPayload,
            'recall_strictness' => 'strict',
            'group_id'   => $otherGroupId,        // attempt to redirect the write via payload, not the resolver
            'id'         => 99999,
            'created_at' => '2000-01-01',
        ]);

        // The row was created under the RESOLVED group (the manager's own),
        // never under the attacker-supplied group_id/id/created_at.
        $row = RecallSettings::where('group_id', $group->id)->first();
        $this->assertNotNull($row);
        $this->assertNotEquals(99999, $row->id);
        $this->assertNotEquals('2000-01-01', $row->created_at->format('Y-m-d'));
        $this->assertDatabaseMissing('recall_settings', ['group_id' => $otherGroupId]);
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
