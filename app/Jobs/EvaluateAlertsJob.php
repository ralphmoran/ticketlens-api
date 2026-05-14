<?php

namespace App\Jobs;

use App\Models\AlertSetting;
use App\Models\SentAlertLog;
use App\Models\SlackIntegration;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Services\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    private const COOLDOWN_HOURS = [
        'needs_response' => 4,
        'aging'          => 24,
    ];

    private const FLAG_TO_TYPE = [
        'needs-response' => 'needs_response',
        'aging'          => 'aging',
    ];

    public function __construct(
        private readonly int $userId,
        private readonly int $snapshotId,
    ) {}

    public function getUserId(): int { return $this->userId; }
    public function getSnapshotId(): int { return $this->snapshotId; }

    public function handle(SlackService $slack): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $group = $user->groups()->first();
        if (! $group) {
            return;
        }

        $integration = SlackIntegration::where('group_id', $group->id)
            ->whereNotNull('channel_id')
            ->first();
        if (! $integration) {
            return;
        }

        $settings = AlertSetting::where('group_id', $group->id)->first();
        if (! $settings) {
            return;
        }

        $snapshot = TriageSnapshot::find($this->snapshotId);
        if (! $snapshot) {
            return;
        }

        foreach ($snapshot->tickets as $ticket) {
            foreach ($ticket['flags'] ?? [] as $flag) {
                $type = self::FLAG_TO_TYPE[$flag] ?? null;
                if (! $type || ! $this->isEnabled($settings, $type)) {
                    continue;
                }

                $key = $ticket['key'] ?? null;
                if (! $key) {
                    continue;
                }

                if (SentAlertLog::recentlySent($group->id, $type, $key, self::COOLDOWN_HOURS[$type])) {
                    continue;
                }

                $slack->postMessage(
                    $integration->bot_token,
                    $integration->channel_id,
                    $this->formatMessage($type, $ticket),
                );

                SentAlertLog::create([
                    'group_id'     => $group->id,
                    'alert_type'   => $type,
                    'ticket_key'   => $key,
                    'triggered_at' => now(),
                ]);
            }
        }
    }

    private function isEnabled(AlertSetting $settings, string $type): bool
    {
        return match ($type) {
            'needs_response' => $settings->needs_response_enabled,
            'aging'          => $settings->aging_enabled,
            default          => false,
        };
    }

    private function formatMessage(string $type, array $ticket): string
    {
        $key      = $ticket['key'] ?? '?';
        $summary  = $ticket['summary'] ?? '';
        $assignee = $ticket['assignee'] ? " (assigned to {$ticket['assignee']})" : '';
        $link     = $ticket['url'] ? "<{$ticket['url']}|{$key}>" : $key;

        return match ($type) {
            'needs_response' => ":speech_balloon: *Needs response:* {$link} — {$summary}{$assignee}",
            'aging'          => ":hourglass_flowing_sand: *Aging ticket:* {$link} — {$summary}{$assignee}",
            default          => ":bell: Alert on {$link}",
        };
    }
}
