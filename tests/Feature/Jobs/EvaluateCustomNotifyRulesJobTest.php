<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EvaluateCustomNotifyRulesJob;
use App\Models\Group;
use App\Models\SentAlertLog;
use App\Models\SlackIntegration;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class EvaluateCustomNotifyRulesJobTest extends TestCase
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

    private function makeSlack(Group $group): SlackIntegration
    {
        return SlackIntegration::create([
            'group_id'       => $group->id,
            'connected_by'   => $group->owner_id,
            'workspace_id'   => 'W123',
            'workspace_name' => 'Acme',
            'bot_token'      => 'xoxb-test',
            'channel_id'     => 'C001',
            'channel_name'   => 'triages',
        ]);
    }

    private function makeCustomRule(Group $group, array $rules, bool $enabled = true, ?int $cooldownHours = null): WorkflowRule
    {
        $config = ['rules' => $rules];
        if ($cooldownHours !== null) {
            $config['cooldown_hours'] = $cooldownHours;
        }

        return WorkflowRule::create([
            'group_id' => $group->id,
            'type'     => 'custom',
            'enabled'  => $enabled,
            'config'   => $config,
        ]);
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

    private function ticket(string $key, array $overrides = []): array
    {
        return array_merge([
            'key'      => $key,
            'summary'  => "Summary for {$key}",
            'status'   => 'Open',
            'priority' => 'Medium',
            'labels'   => [],
            'url'      => "https://jira.example.com/browse/{$key}",
        ], $overrides);
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

        (new EvaluateCustomNotifyRulesJob(999999, 1))->handle($slack);
    }

    public function test_no_op_when_no_enabled_custom_rule(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_no_op_when_custom_rule_disabled(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ], enabled: false);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_no_op_when_no_slack_integration(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ]);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_no_op_when_no_ticket_matches(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ]);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Low'])]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    public function test_sends_notify_alert_and_logs_it(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1 always urgent'],
        ]);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'P1 always urgent') && str_contains($text, 'P-1')
        ));

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);

        $this->assertDatabaseHas('sent_alert_logs', [
            'group_id'   => $group->id,
            'alert_type' => 'custom_notify',
            'ticket_key' => 'P-1',
            'rule_id'    => null,
        ]);
    }

    public function test_sends_notify_alert_for_clear_urgency_ticket_with_empty_flags(): void
    {
        // Regression pin for the CLI --push fix (skills/jtb/scripts/fetch-my-tickets.mjs):
        // a 'clear'-urgency ticket is pushed with flags: [] (ticket-payload.mjs) — this job
        // must match it on priority alone, exactly like any other pushed ticket. Before the
        // CLI fix, a clear-urgency ticket never reached the snapshot at all; this proves the
        // server-side half of the fix was always correct once the ticket arrives here.
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1 always urgent'],
        ]);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest', 'flags' => []])]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'P1 always urgent') && str_contains($text, 'P-1')
        ));

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);

        $this->assertDatabaseHas('sent_alert_logs', [
            'group_id'   => $group->id,
            'alert_type' => 'custom_notify',
            'ticket_key' => 'P-1',
        ]);
    }

    public function test_escapes_mrkdwn_in_reason(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => '<!channel> urgent'],
        ]);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => ! str_contains($text, '<!channel>') && str_contains($text, '&lt;!channel&gt;')
        ));

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_escapes_mrkdwn_in_url(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-1', ['priority' => 'Highest', 'url' => 'https://evil.example>*pwned*<!channel|']),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => ! str_contains($text, '<!channel')
                && ! preg_match('/https:\/\/evil\.example>/', $text)
                && str_contains($text, '&lt;!channel')
        ));

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_does_not_resend_within_cooldown_window(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ]);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'custom_notify',
            'ticket_key'   => 'P-1',
            'triggered_at' => now()->subHours(1),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_honors_shorter_custom_cooldown_than_the_4h_default(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ], enabled: true, cooldownHours: 1);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'custom_notify',
            'ticket_key'   => 'P-1',
            'triggered_at' => now()->subHours(2), // past the 1h custom cooldown, still within the old 4h default
        ]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once();

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_honors_longer_custom_cooldown_than_the_4h_default(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ], enabled: true, cooldownHours: 24);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'custom_notify',
            'ticket_key'   => 'P-1',
            'triggered_at' => now()->subHours(5), // past the old 4h default, still within the 24h custom cooldown
        ]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_resends_after_cooldown_expires(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ]);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        SentAlertLog::create([
            'group_id'     => $group->id,
            'alert_type'   => 'custom_notify',
            'ticket_key'   => 'P-1',
            'triggered_at' => now()->subHours(5),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once();

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_multiple_matching_notify_rules_collapse_to_single_log_row_using_first_reason(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'first rule reason'],
            ['match' => ['label' => 'critical'],    'action' => 'notify', 'reason' => 'second rule reason'],
        ]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-1', ['priority' => 'Highest', 'labels' => ['critical']]),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'first rule reason') && ! str_contains($text, 'second rule reason')
        ));

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);

        $this->assertSame(1, SentAlertLog::where('group_id', $group->id)->where('alert_type', 'custom_notify')->count());
    }

    public function test_ignores_force_urgent_and_ignore_action_rules(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'force-urgent', 'reason' => 'local only'],
            ['match' => ['priority' => 'Highest'], 'action' => 'ignore',       'reason' => 'local only'],
        ]);
        $snapshot = $this->makeSnapshot($user, [$this->ticket('P-1', ['priority' => 'Highest'])]);

        $slack = $this->mockSlack();
        $slack->shouldNotReceive('postMessage');

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_malformed_rule_is_skipped_other_tickets_still_processed(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['action' => 'notify'], // missing match — malformed, must not throw
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'valid rule'],
        ]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-1', ['priority' => 'Highest']),
            $this->ticket('P-2', ['priority' => 'Low']),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->once()->with('xoxb-test', 'C001', Mockery::on(
            fn ($text) => str_contains($text, 'P-1')
        ));

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
    }

    public function test_handles_multiple_tickets_independently(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ]);
        $snapshot = $this->makeSnapshot($user, [
            $this->ticket('P-1', ['priority' => 'Highest']),
            $this->ticket('P-2', ['priority' => 'Highest']),
        ]);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->twice();

        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);

        $this->assertSame(2, SentAlertLog::where('group_id', $group->id)->where('alert_type', 'custom_notify')->count());
    }

    public function test_cooldown_check_does_not_grow_with_matched_ticket_count(): void
    {
        $user  = $this->makeTeamUser();
        $group = $user->groups()->first();
        $this->makeSlack($group);
        $this->makeCustomRule($group, [
            ['match' => ['priority' => 'Highest'], 'action' => 'notify', 'reason' => 'P1'],
        ]);
        $manyTickets = array_map(
            fn ($i) => $this->ticket("P-{$i}", ['priority' => 'Highest']),
            range(1, 20),
        );
        $snapshot = $this->makeSnapshot($user, $manyTickets);

        $slack = $this->mockSlack();
        $slack->shouldReceive('postMessage')->times(20);

        DB::enableQueryLog();
        DB::flushQueryLog();
        (new EvaluateCustomNotifyRulesJob($user->id, $snapshot->id))->handle($slack);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Fixed set of setup queries (group/rule/integration/snapshot) plus one
        // batched cooldown lookup + N inserts for the 20 matched tickets — must
        // NOT scale with an extra SELECT per matched ticket (N+1 regression guard).
        $this->assertLessThan(30, $queryCount, "Query count scaled with matched-ticket count: {$queryCount} queries for 20 tickets");
    }

    // ── failed() hook — logs on retry exhaustion, does not swallow the exception ──

    public function test_failed_hook_exists_and_is_callable(): void
    {
        $job = new EvaluateCustomNotifyRulesJob(1, 1);
        $this->assertTrue(method_exists($job, 'failed'));
    }
}
