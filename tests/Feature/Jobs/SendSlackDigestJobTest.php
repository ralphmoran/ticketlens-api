<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EvaluateAlertsJob;
use App\Jobs\SendSlackDigestJob;
use App\Models\AlertSetting;
use App\Models\Group;
use App\Models\SlackDigestSchedule;
use App\Models\SlackIntegration;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Services\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SendSlackDigestJobTest extends TestCase
{
    use RefreshDatabase;

    // ── Lock tests — existing invariants must not change ─────────────────────

    public function test_evaluate_alerts_job_class_still_exists(): void
    {
        $this->assertTrue(class_exists(EvaluateAlertsJob::class));
    }

    public function test_alert_setting_existing_fillable_unchanged(): void
    {
        $fillable = (new AlertSetting())->getFillable();

        foreach ([
            'needs_response_enabled', 'needs_response_cooldown_hours',
            'aging_enabled',          'aging_cooldown_hours',
            'compliance_gap_enabled', 'compliance_gap_cooldown_hours',
        ] as $col) {
            $this->assertContains($col, $fillable, "AlertSetting fillable missing: {$col}");
        }
    }

    // ── SlackDigestSchedule::isDue() ─────────────────────────────────────────

    public function test_is_due_true_when_day_time_match_and_never_delivered(): void
    {
        $schedule = $this->makeSchedule(['day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC', 'last_delivered_at' => null]);

        $this->assertTrue($schedule->isDue(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC'))); // Monday
    }

    public function test_is_due_false_when_wrong_day(): void
    {
        $schedule = $this->makeSchedule(['day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC']);

        $this->assertFalse($schedule->isDue(\Carbon\Carbon::parse('2026-05-19 09:00:00', 'UTC'))); // Tuesday
    }

    public function test_is_due_false_when_wrong_minute(): void
    {
        $schedule = $this->makeSchedule(['day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC']);

        $this->assertFalse($schedule->isDue(\Carbon\Carbon::parse('2026-05-18 08:59:00', 'UTC')));
    }

    public function test_is_due_false_when_delivered_within_23_hours(): void
    {
        $schedule = $this->makeSchedule([
            'day_of_week'       => 1,
            'deliver_at'        => '09:00',
            'timezone'          => 'UTC',
            'last_delivered_at' => \Carbon\Carbon::parse('2026-05-18 08:00:00', 'UTC'),
        ]);

        $this->assertFalse($schedule->isDue(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')));
    }

    public function test_is_due_respects_timezone(): void
    {
        $schedule = $this->makeSchedule(['day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'America/New_York', 'last_delivered_at' => null]);

        // 13:00 UTC = 09:00 New York (UTC-4 in May)
        $this->assertTrue($schedule->isDue(\Carbon\Carbon::parse('2026-05-18 13:00:00', 'UTC')));
    }

    // ── SendSlackDigestJob — skip conditions ─────────────────────────────────

    public function test_job_skips_when_no_slack_integration(): void
    {
        [$group] = $this->makeGroupWithUser();
        $this->makeSchedule(['group_id' => $group->id, 'day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC']);

        $slack = Mockery::mock(SlackService::class);
        $slack->shouldNotReceive('postMessage');
        $slack->shouldNotReceive('postDm');

        (new SendSlackDigestJob(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')))->handle($slack);
    }

    public function test_job_skips_inactive_schedule(): void
    {
        [$group, $user] = $this->makeGroupWithUser();
        $this->makeSlack($group);
        $this->makeSnapshot($user);
        $this->makeSchedule(['group_id' => $group->id, 'day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC', 'active' => false]);

        $slack = Mockery::mock(SlackService::class);
        $slack->shouldNotReceive('postMessage');
        $slack->shouldNotReceive('postDm');

        (new SendSlackDigestJob(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')))->handle($slack);
    }

    public function test_job_skips_schedule_not_yet_due(): void
    {
        [$group, $user] = $this->makeGroupWithUser();
        $this->makeSlack($group);
        $this->makeSnapshot($user);
        $this->makeSchedule(['group_id' => $group->id, 'day_of_week' => 2, 'deliver_at' => '09:00', 'timezone' => 'UTC']); // Tuesday

        $slack = Mockery::mock(SlackService::class);
        $slack->shouldNotReceive('postMessage');
        $slack->shouldNotReceive('postDm');

        (new SendSlackDigestJob(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')))->handle($slack); // Monday
    }

    public function test_job_skips_when_no_snapshot_for_group(): void
    {
        [$group] = $this->makeGroupWithUser();
        $this->makeSlack($group);
        $this->makeSchedule(['group_id' => $group->id, 'day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC']);

        $slack = Mockery::mock(SlackService::class);
        $slack->shouldNotReceive('postMessage');

        (new SendSlackDigestJob(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')))->handle($slack);
    }

    // ── SendSlackDigestJob — channel delivery with all stats ──────────────────

    public function test_job_posts_to_channel_with_all_stats(): void
    {
        [$group, $user] = $this->makeGroupWithUser();
        $integration = $this->makeSlack($group);

        $this->makeSnapshot($user, [
            $this->ticket('PROJ-1', ['aging', 'needs-response'], 'In Progress'),
            $this->ticket('PROJ-2', [],                          'Done',       'gap'),
            $this->ticket('PROJ-3', [],                          'To Do'),
        ]);

        $schedule = $this->makeSchedule([
            'group_id'     => $group->id,
            'day_of_week'  => 1,
            'deliver_at'   => '09:00',
            'timezone'     => 'UTC',
            'target_type'  => 'channel',
            'target_id'    => $integration->channel_id,
            'target_label' => '#general',
        ]);

        $slack = Mockery::mock(SlackService::class);
        $slack->shouldReceive('postMessage')
            ->once()
            ->withArgs(function (string $token, string $channelId, string $text) use ($integration): bool {
                return $token     === $integration->bot_token
                    && $channelId === $integration->channel_id
                    && str_contains($text, 'Total: 3')
                    && str_contains($text, 'Needs response: 1')
                    && str_contains($text, 'Aging: 1')
                    && str_contains($text, 'Compliance gaps: 1')
                    && str_contains($text, 'PROJ-1');
            });

        (new SendSlackDigestJob(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')))->handle($slack);

        $this->assertNotNull($schedule->fresh()->last_delivered_at);
    }

    public function test_job_posts_dm_when_target_type_is_user(): void
    {
        [$group, $user] = $this->makeGroupWithUser();
        $integration = $this->makeSlack($group);
        $this->makeSnapshot($user);

        $this->makeSchedule([
            'group_id'     => $group->id,
            'day_of_week'  => 1,
            'deliver_at'   => '09:00',
            'timezone'     => 'UTC',
            'target_type'  => 'user',
            'target_id'    => 'U999',
            'target_label' => 'Charlie',
        ]);

        $slack = Mockery::mock(SlackService::class);
        $slack->shouldReceive('postDm')
            ->once()
            ->withArgs(fn (string $token, string $userId): bool =>
                $token  === $integration->bot_token
                && $userId === 'U999'
            );

        (new SendSlackDigestJob(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')))->handle($slack);
    }

    public function test_job_skips_when_group_deleted(): void
    {
        [$group] = $this->makeGroupWithUser();
        $this->makeSlack($group);
        $this->makeSchedule(['group_id' => $group->id, 'day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC']);
        $group->delete();

        $slack = Mockery::mock(SlackService::class);
        $slack->shouldNotReceive('postMessage');
        $slack->shouldNotReceive('postDm');

        (new SendSlackDigestJob(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')))->handle($slack);
    }

    public function test_job_fires_multiple_schedules_for_same_group(): void
    {
        [$group, $user] = $this->makeGroupWithUser();
        $integration = $this->makeSlack($group);
        $this->makeSnapshot($user);

        $this->makeSchedule(['group_id' => $group->id, 'day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC', 'target_type' => 'channel', 'target_id' => $integration->channel_id, 'target_label' => '#general']);
        $this->makeSchedule(['group_id' => $group->id, 'day_of_week' => 1, 'deliver_at' => '09:00', 'timezone' => 'UTC', 'target_type' => 'user',    'target_id' => 'U111',                   'target_label' => 'Alice']);

        $slack = Mockery::mock(SlackService::class);
        $slack->shouldReceive('postMessage')->once();
        $slack->shouldReceive('postDm')->once();

        (new SendSlackDigestJob(\Carbon\Carbon::parse('2026-05-18 09:00:00', 'UTC')))->handle($slack);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeGroupWithUser(): array
    {
        $user  = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group = Group::create(['name' => "Team {$user->id}", 'owner_id' => $user->id]);
        $group->members()->attach($user->id);
        return [$group, $user];
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
            'channel_name'   => '#general',
        ]);
    }

    private function makeSchedule(array $attrs = []): SlackDigestSchedule
    {
        static $groupCounter = 0;

        if (! isset($attrs['group_id'])) {
            [$group] = $this->makeGroupWithUser();
            $attrs['group_id'] = $group->id;
        }

        return SlackDigestSchedule::create(array_merge([
            'day_of_week'  => 1,
            'deliver_at'   => '09:00',
            'timezone'     => 'UTC',
            'target_type'  => 'channel',
            'target_id'    => 'C001',
            'target_label' => '#general',
            'active'       => true,
        ], $attrs));
    }

    private function makeSnapshot(User $user, array $tickets = []): TriageSnapshot
    {
        if (empty($tickets)) {
            $tickets = [$this->ticket('PROJ-1', [], 'In Progress')];
        }

        return TriageSnapshot::create([
            'user_id'          => $user->id,
            'license_key_hash' => hash('sha256', 'test-key'),
            'profile'          => 'test',
            'tickets'          => $tickets,
            'ticket_count'     => count($tickets),
            'captured_at'      => now(),
        ]);
    }

    private function ticket(string $key, array $flags = [], string $status = 'In Progress', string $complianceStatus = 'unknown'): array
    {
        return [
            'key'               => $key,
            'summary'           => "Summary for {$key}",
            'status'            => $status,
            'assignee'          => 'Dev User',
            'flags'             => $flags,
            'compliance_status' => $complianceStatus,
            'url'               => "https://jira.example.com/browse/{$key}",
        ];
    }
}
