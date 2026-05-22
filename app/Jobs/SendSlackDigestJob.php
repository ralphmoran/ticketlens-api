<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\SlackDigestSchedule;
use App\Models\SlackIntegration;
use App\Models\TriageSnapshot;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSlackDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly Carbon $now,
    ) {}

    public function handle(SlackService $slack): void
    {
        SlackDigestSchedule::where('active', true)->chunk(100, function ($schedules) use ($slack) {
            foreach ($schedules as $schedule) {
                if ($schedule->isDue($this->now)) {
                    $this->processSchedule($schedule, $slack);
                }
            }
        });
    }

    private function processSchedule(SlackDigestSchedule $schedule, SlackService $slack): void
    {
        $integration = SlackIntegration::where('group_id', $schedule->group_id)
            ->whereNotNull('channel_id')
            ->first();

        if (! $integration) {
            return;
        }

        $group = Group::find($schedule->group_id);
        if (! $group) {
            return;
        }

        $userIds = $group->users()->pluck('users.id');
        if ($userIds->isEmpty()) {
            return;
        }

        $snapshot = TriageSnapshot::whereIn('user_id', $userIds)
            ->latest('captured_at')
            ->first();

        if (! $snapshot) {
            return;
        }

        $message = $this->buildMessage($snapshot->tickets ?? [], $group);

        if ($schedule->target_type === 'user') {
            $slack->postDm($integration->bot_token, $schedule->target_id, $message);
        } else {
            $slack->postMessage($integration->bot_token, $schedule->target_id, $message);
        }

        $schedule->update(['last_delivered_at' => $this->now]);
    }

    private function buildMessage(array $tickets, Group $group): string
    {
        $total          = count($tickets);
        $needsResponse  = 0;
        $aging          = 0;
        $complianceGaps = 0;
        $agingTickets   = [];

        foreach ($tickets as $ticket) {
            $flags = $ticket['flags'] ?? [];

            if (in_array('needs-response', $flags, true)) {
                $needsResponse++;
            }

            if (in_array('aging', $flags, true)) {
                $aging++;
                $agingTickets[] = $ticket;
            }

            if (strtolower($ticket['status'] ?? '') === 'done'
                && strtolower($ticket['compliance_status'] ?? '') === 'gap'
            ) {
                $complianceGaps++;
            }
        }

        $lines = [
            ":bar_chart: *Weekly Digest — {$group->name}* | " . $this->now->format('D, M j Y'),
            '',
            "• Total: {$total}",
            "• Needs response: {$needsResponse} :speech_balloon:",
            "• Aging: {$aging} :hourglass_flowing_sand:",
            "• Compliance gaps: {$complianceGaps} :warning:",
        ];

        $topAging = array_slice($agingTickets, 0, 3);
        if (! empty($topAging)) {
            $lines[] = '';
            $lines[] = ':fire: *Top aging tickets:*';
            foreach ($topAging as $t) {
                $key      = $t['key'] ?? '?';
                $summary  = $t['summary'] ?? '';
                $assignee = ($t['assignee'] ?? null) ? " ({$t['assignee']})" : '';
                $link     = ($t['url'] ?? null) ? "<{$t['url']}|{$key}>" : $key;
                $lines[]  = "  • {$link} — {$summary}{$assignee}";
            }
        }

        return implode("\n", $lines);
    }
}
