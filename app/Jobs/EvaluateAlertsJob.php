<?php

namespace App\Jobs;

use App\Models\AlertSetting;
use App\Models\CustomAlertRule;
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
use Illuminate\Support\Collection;

class EvaluateAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    /** @var array<string, list<\Carbon\Carbon>> Keyed by "type|key|ruleId" — built once per run. */
    private array $sentCache = [];

    private const FLAG_TO_TYPE = [
        'needs-response' => 'needs_response',
        'aging'          => 'aging',
    ];

    private const COMPLIANCE_GAP_TYPE = 'compliance_gap';

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

        $rules = CustomAlertRule::where('group_id', $group->id)->where('enabled', true)->get();

        $this->buildSentCache($group->id, $settings);

        foreach ($snapshot->tickets as $ticket) {
            $key = $ticket['key'] ?? null;
            if (! $key) {
                continue;
            }

            // Flag-based alerts (needs_response, aging)
            foreach ($ticket['flags'] ?? [] as $flag) {
                $type = self::FLAG_TO_TYPE[$flag] ?? null;
                if (! $type) {
                    continue;
                }

                $this->dispatchAlert($slack, $integration, $settings, $rules, $group->id, $type, $key, $ticket);
            }

            // Status-based alert: compliance gap
            if (strtolower($ticket['status'] ?? '') === 'done'
                && strtolower($ticket['compliance_status'] ?? '') === 'gap'
            ) {
                $this->dispatchAlert($slack, $integration, $settings, $rules, $group->id, self::COMPLIANCE_GAP_TYPE, $key, $ticket);
            }
        }
    }

    private function dispatchAlert(
        SlackService       $slack,
        SlackIntegration   $integration,
        AlertSetting       $settings,
        Collection         $rules,
        int                $groupId,
        string             $type,
        string             $key,
        array              $ticket,
    ): void {
        // Intentional: channel alert and per-rule DMs share the same cooldown window.
        // Rules are a complementary notification path, not an independent cadence.
        $cooldown = $this->cooldownHours($settings, $type);

        if ($this->isEnabled($settings, $type)) {
            if (! $this->recentlySentInMemory($type, $key, $cooldown)) {
                $slack->postMessage(
                    $integration->bot_token,
                    $integration->channel_id,
                    $this->formatMessage($type, $ticket),
                );
                SentAlertLog::create([
                    'group_id'     => $groupId,
                    'alert_type'   => $type,
                    'ticket_key'   => $key,
                    'triggered_at' => now(),
                ]);
                $this->trackSent($type, $key);
            }
        }

        foreach ($rules->where('alert_type', $type) as $rule) {
            if ($this->recentlySentInMemory($type, $key, $cooldown, $rule->id)) {
                continue;
            }
            $slack->postDm(
                $integration->bot_token,
                $rule->target_id,
                $this->formatMessage($type, $ticket),
            );
            SentAlertLog::create([
                'group_id'     => $groupId,
                'alert_type'   => $type,
                'ticket_key'   => $key,
                'rule_id'      => $rule->id,
                'triggered_at' => now(),
            ]);
            $this->trackSent($type, $key, $rule->id);
        }
    }

    private function buildSentCache(int $groupId, AlertSetting $settings): void
    {
        $maxCooldown = max(
            (int) ($settings->needs_response_cooldown_hours ?? 4),
            (int) ($settings->aging_cooldown_hours          ?? 4),
            (int) ($settings->compliance_gap_cooldown_hours  ?? 4),
        );

        $this->sentCache = [];
        SentAlertLog::where('group_id', $groupId)
            ->where('triggered_at', '>=', now()->subHours($maxCooldown))
            ->get()
            ->each(function (SentAlertLog $log): void {
                $k = $log->alert_type . '|' . $log->ticket_key . '|' . ($log->rule_id ?? '');
                $this->sentCache[$k][] = $log->triggered_at;
            });
    }

    private function recentlySentInMemory(string $type, string $key, int $cooldownHours, ?int $ruleId = null): bool
    {
        $k = $type . '|' . $key . '|' . ($ruleId ?? '');
        if (! array_key_exists($k, $this->sentCache)) {
            return false;
        }
        $cutoff = now()->subHours($cooldownHours);
        foreach ($this->sentCache[$k] as $ts) {
            if ($ts->greaterThanOrEqualTo($cutoff)) {
                return true;
            }
        }
        return false;
    }

    private function trackSent(string $type, string $key, ?int $ruleId = null): void
    {
        $k = $type . '|' . $key . '|' . ($ruleId ?? '');
        $this->sentCache[$k][] = now();
    }

    private function cooldownHours(AlertSetting $settings, string $type): int
    {
        return match ($type) {
            'needs_response' => $settings->needs_response_cooldown_hours,
            'aging'          => $settings->aging_cooldown_hours,
            'compliance_gap' => $settings->compliance_gap_cooldown_hours,
            default          => 4,
        };
    }

    private function isEnabled(AlertSetting $settings, string $type): bool
    {
        return match ($type) {
            'needs_response' => $settings->needs_response_enabled,
            'aging'          => $settings->aging_enabled,
            'compliance_gap' => $settings->compliance_gap_enabled,
            default          => false,
        };
    }

    private function formatMessage(string $type, array $ticket): string
    {
        $key      = $ticket['key'] ?? '?';
        $summary  = $ticket['summary'] ?? '';
        $assignee = ($ticket['assignee'] ?? null) ? " (assigned to {$ticket['assignee']})" : '';
        $link     = ($ticket['url'] ?? null) ? "<{$ticket['url']}|{$key}>" : $key;

        return match ($type) {
            'needs_response' => ":speech_balloon: *Needs response:* {$link} — {$summary}{$assignee}",
            'aging'          => ":hourglass_flowing_sand: *Aging ticket:* {$link} — {$summary}{$assignee}",
            'compliance_gap' => ":warning: *Compliance gap:* {$link} — Done but requirements incomplete{$assignee}",
            default          => ":bell: Alert on {$link}",
        };
    }
}
