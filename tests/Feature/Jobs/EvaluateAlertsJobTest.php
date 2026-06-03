<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EvaluateAlertsJob;
use App\Models\AlertSetting;
use App\Models\CustomAlertRule;
use App\Models\Group;
use App\Models\License;
use App\Models\SentAlertLog;
use App\Models\SlackIntegration;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EvaluateAlertsJobTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeTeamUser(): User
    {
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return $user;
    }

    private function makeSlack(Group $group, bool $withChannel = true): SlackIntegration
    {
        return SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $group->owner_id,
            'workspace_id'   => 'W123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
            'channel_id'     => $withChannel ? 'C001' : null,
            'channel_name'   => $withChannel ? 'triages' : null,
        ]);
    }

    private function makeAlertSettings(Group $group, array $overrides = []): AlertSetting
    {
        return AlertSetting::create(array_merge([
            'group_id'               => $group->id,
            'needs_response_enabled' => true,
            'aging_enabled'          => true,
        ], $overrides));
    }

    private function makeSnapshot(User $user, array $tickets): TriageSnapshot
    {
        return TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'test-key'),
            'profile'          => 'test',
            'tickets'          => $tickets,
            'ticket_count'     => count($tickets),
            'captured_at'      => now(),
        ]);
    }

    private function ticket(
        string $key,
        array  $flags,
        string $status            = 'Code Review',
        string $complianceStatus  = 'unknown',
    ): array {
        return [
            'key'               => $key,
            'summary'           => "Summary for {$key}",
            'status'            => $status,
            'assignee'          => 'Dev User',
            'flags'             => $flags,
            'compliance_status' => $complianceStatus,
            'url'               => "https://jira.example.com/browse/{$key}",
            'last_updated'      => now()->toISOString(),
        ];
    }

    private function makeRule(Group $group, array $overrides = []): CustomAlertRule
    {
        return CustomAlertRule::create(array_merge([
            'group_id'     => $group->id,
            'alert_type'   => 'needs_response',
            'integration'  => 'slack',
            'target_id'    => 'U999',
            'target_label' => 'On-Call',
            'enabled'      => true,
        ], $overrides));
    }

    private function mockSlack(): SlackService
    {
        $mock = Mockery::mock(SlackService::class);
        $this->app->instance(SlackService::class, $mock);
        return $mock;
    }

    // ── No-op guards ─────────────────────────────────────────────────────────

    public function test_no_op_when_user_not_found(): void
    {
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob(99999, 1))->handle($slack);
    }

    public function test_no_op_when_user_has_no_group(): void
    {
        $user  = User::factory()->create(['tier' => 'team']);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        $snapshot = TriageSnapshot::create([
            'user_id' => $user->id, 'license_key_hash' => 'abc', 'profile' => 'x',
            'tickets' => [], 'ticket_count' => 0, 'captured_at' => now(),
        ]);

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_no_op_when_no_slack_integration(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeAlertSettings($group);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_no_op_when_slack_integration_has_no_channel(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group, withChannel: false);
        $this->makeAlertSettings($group);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_no_op_when_no_alert_settings(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_no_op_when_all_alerts_disabled(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['needs_response_enabled' => false, 'aging_enabled' => false]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-1', ['needs-response']),
            $this->ticket('P-2', ['aging']),
        ]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_no_op_for_clear_flag_tickets(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', [])]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    // ── Alert firing ─────────────────────────────────────────────────────────

    public function test_sends_needs_response_alert_and_logs_it(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'Needs response') && str_contains($text, 'P-1')
        ));

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);

        $this->assertDatabaseHas('sent_alert_logs', [
            'group_id'   => $group->id,
            'alert_type' => 'needs_response',
            'ticket_key' => 'P-1',
        ]);
    }

    public function test_sends_aging_alert_and_logs_it(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-2', ['aging'])]);
        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'Aging ticket') && str_contains($text, 'P-2')
        ));

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);

        $this->assertDatabaseHas('sent_alert_logs', [
            'group_id'   => $group->id,
            'alert_type' => 'aging',
            'ticket_key' => 'P-2',
        ]);
    }

    public function test_only_enabled_alerts_fire(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['needs_response_enabled' => false, 'aging_enabled' => true]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-1', ['needs-response']),
            $this->ticket('P-2', ['aging']),
        ]);
        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'Aging ticket')
        ));

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    // ── Cooldown deduplication ────────────────────────────────────────────────

    public function test_does_not_resend_within_cooldown_window(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group);

        // Pre-seed a recent alert log for P-1
        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'needs_response',
            'ticket_key'   => 'P-1',
            'triggered_at' => now()->subHour(),
        ]);

        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_resends_after_cooldown_expires(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group);

        // Pre-seed an old alert log — outside the 4h cooldown
        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'needs_response',
            'ticket_key'   => 'P-1',
            'triggered_at' => now()->subHours(5),
        ]);

        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once();

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_handles_multiple_tickets_independently(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-1', ['needs-response']),
            $this->ticket('P-2', ['aging']),
            $this->ticket('P-3', []),
        ]);
        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->twice();

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);

        $this->assertSame(2, SentAlertLog::count());
    }

    // ── Custom cooldown hours ─────────────────────────────────────────────────

    public function test_cooldown_hours_read_from_settings(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['needs_response_cooldown_hours' => 12]);

        // Log is 8h old — within the custom 12h cooldown, should be suppressed
        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'needs_response',
            'ticket_key'   => 'P-1',
            'triggered_at' => now()->subHours(8),
        ]);

        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack    = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    // ── Custom rule DMs ───────────────────────────────────────────────────────

    public function test_custom_rule_dm_fires_for_matching_flag(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['needs_response_enabled' => false]);
        $this->makeRule($group); // alert_type = needs_response, target_id = U999
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage'); // channel alert is disabled
        $slack->shouldReceive('postDm')->once()->with('xoxb-test', 'U999', Mockery::on(
            fn ($text) => str_contains($text, 'P-1')
        ));

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);

        $this->assertDatabaseHas('sent_alert_logs', [
            'alert_type' => 'needs_response',
            'ticket_key' => 'P-1',
        ]);
    }

    public function test_custom_rule_dm_suppressed_within_cooldown(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['needs_response_enabled' => false]);
        $rule = $this->makeRule($group);

        // Pre-seed a rule-scoped log within cooldown
        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'needs_response',
            'ticket_key'   => 'P-1',
            'rule_id'      => $rule->id,
            'triggered_at' => now()->subHour(),
        ]);

        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack    = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');
        $slack->shouldNotReceive('postDm');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_custom_rule_cooldown_independent_from_channel_alert(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group); // both enabled
        $rule = $this->makeRule($group);

        // Channel alert is recently logged (rule_id = NULL) — blocks channel alert only
        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'needs_response',
            'ticket_key'   => 'P-1',
            'rule_id'      => null,
            'triggered_at' => now()->subHour(),
        ]);

        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack    = $this->mockSlack();
        $slack->shouldNotReceive('postMessage'); // channel alert blocked by its own log
        $slack->shouldReceive('postDm')->once(); // rule DM has no matching rule-scoped log → fires

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_disabled_custom_rule_is_skipped(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['needs_response_enabled' => false]);
        $this->makeRule($group, ['enabled' => false]);

        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack    = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');
        $slack->shouldNotReceive('postDm');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_multiple_custom_rules_each_receive_dm(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['needs_response_enabled' => false]);
        $this->makeRule($group, ['target_id' => 'U001', 'target_label' => 'Alice']);
        $this->makeRule($group, ['target_id' => 'U002', 'target_label' => 'Bob']);

        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['needs-response'])]);
        $slack    = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');
        $slack->shouldReceive('postDm')->twice();

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);

        $this->assertSame(2, SentAlertLog::count());
    }

    // ── Compliance gap alert ──────────────────────────────────────────────────

    public function test_fires_compliance_gap_when_status_done_and_compliance_gap(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['compliance_gap_enabled' => true]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-5', [], 'Done', 'gap'),
        ]);
        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'Compliance gap') && str_contains($text, 'P-5')
        ));

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);

        $this->assertDatabaseHas('sent_alert_logs', [
            'group_id'   => $group->id,
            'alert_type' => 'compliance_gap',
            'ticket_key' => 'P-5',
        ]);
    }

    public function test_does_not_fire_compliance_gap_when_status_not_done(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['compliance_gap_enabled' => true]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-5', [], 'In Progress', 'gap'),
        ]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_does_not_fire_compliance_gap_when_compliance_is_ok(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['compliance_gap_enabled' => true]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-5', [], 'Done', 'ok'),
        ]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_does_not_fire_compliance_gap_when_disabled(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['compliance_gap_enabled' => false]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-5', [], 'Done', 'gap'),
        ]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_compliance_gap_cooldown_respected(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['compliance_gap_enabled' => true]);

        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'compliance_gap',
            'ticket_key'   => 'P-5',
            'triggered_at' => now()->subHours(2),
        ]);

        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-5', [], 'Done', 'gap'),
        ]);
        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_compliance_gap_custom_rule_dm_fires(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['compliance_gap_enabled' => false]);
        $this->makeRule($group, ['alert_type' => 'compliance_gap', 'target_id' => 'U777']);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-5', [], 'Done', 'gap'),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');
        $slack->shouldReceive('postDm')->once()->with('xoxb-test', 'U777', Mockery::on(
            fn ($text) => str_contains($text, 'P-5')
        ));

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    // ── Stale alert ───────────────────────────────────────────────────────────

    public function test_stale_alert_fires_when_enabled_and_ticket_has_stale_flag(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['stale_enabled' => true, 'stale_cooldown_hours' => 4]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('ST-1', ['stale']),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'ST-1') && str_contains($text, 'Stale')
        ));

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_stale_alert_does_not_fire_when_disabled(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['stale_enabled' => false]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('ST-2', ['stale']),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');
        $slack->shouldNotReceive('postDm');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_stale_alert_respects_cooldown(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, ['stale_enabled' => true, 'stale_cooldown_hours' => 4]);

        // Pre-seed a recent sent log
        \App\Models\SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'stale',
            'ticket_key'   => 'ST-3',
            'triggered_at' => now()->subHour(),
        ]);

        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('ST-3', ['stale']),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_stale_alert_default_disabled_on_missing_stale_settings(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        // No stale_enabled column override — relies on default false
        $this->makeAlertSettings($group);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('ST-4', ['stale']),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_stale_and_aging_alerts_fire_independently_on_same_snapshot(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeAlertSettings($group, [
            'aging_enabled'        => true,
            'aging_cooldown_hours' => 4,
            'stale_enabled'        => true,
            'stale_cooldown_hours' => 4,
        ]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('AG-1', ['aging']),
            $this->ticket('ST-5', ['stale']),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->twice();

        (new EvaluateAlertsJob($user->id, $snapshot->id))->handle($slack);
    }
}
