<?php

namespace App\Jobs;

use App\Models\SentAlertLog;
use App\Models\SlackIntegration;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Services\CustomRuleMatcher;
use App\Services\SlackMrkdwnEscaper;
use App\Services\SlackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches Slack notifications for custom triage rules with action='notify'.
 *
 * Deliberately separate from EvaluateAlertsJob: that job reads CustomAlertRule
 * (a different model entirely) and its cooldown batching is sized for
 * AlertSetting's four fixed alert types — folding this in would either couple
 * an unrelated model or under-cover this alert type's cooldown window.
 *
 * Server is the sole source of truth for the match: it re-evaluates every
 * ticket independently against the group's WorkflowRule config, never
 * trusting a client-asserted match result.
 */
class EvaluateCustomNotifyRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DEFAULT_COOLDOWN_HOURS = 4;
    private const ALERT_TYPE = 'custom_notify';

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

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

        $group = $user->ownedGroup ?? $user->groups()->first();
        if (! $group) {
            return;
        }

        $customRule = WorkflowRule::where('group_id', $group->id)
            ->where('type', 'custom')
            ->where('enabled', true)
            ->first();
        if (! $customRule) {
            return;
        }

        $integration = SlackIntegration::where('group_id', $group->id)
            ->whereNotNull('channel_id')
            ->first();
        if (! $integration) {
            return;
        }

        $snapshot = TriageSnapshot::find($this->snapshotId);
        if (! $snapshot) {
            return;
        }

        $rules         = $customRule->config['rules'] ?? [];
        $cooldownHours = (int) ($customRule->config['cooldown_hours'] ?? self::DEFAULT_COOLDOWN_HOURS);
        $matcher       = new CustomRuleMatcher();
        $escaper       = new SlackMrkdwnEscaper();

        // Batched once, checked in-memory per ticket — avoids a SELECT per
        // matched ticket (a broad match rule could match dozens per push).
        $recentlySentKeys = SentAlertLog::where('group_id', $group->id)
            ->where('alert_type', self::ALERT_TYPE)
            ->where('triggered_at', '>=', now()->subHours($cooldownHours))
            ->pluck('ticket_key')
            ->flip();

        foreach ($snapshot->tickets as $ticket) {
            $key = $ticket['key'] ?? null;
            if (! $key) {
                continue;
            }

            $matched = $matcher->matchingRules($ticket, $rules, 'notify');
            if (empty($matched)) {
                continue;
            }

            if ($recentlySentKeys->has($key)) {
                continue;
            }

            $slack->postMessage(
                $integration->bot_token,
                $integration->channel_id,
                $this->formatMessage($escaper, $matched[0], $ticket),
            );

            SentAlertLog::create([
                'group_id'     => $group->id,
                'alert_type'   => self::ALERT_TYPE,
                'ticket_key'   => $key,
                'triggered_at' => now(),
            ]);

            $recentlySentKeys->put($key, true);
        }
    }

    private function formatMessage(SlackMrkdwnEscaper $escaper, array $rule, array $ticket): string
    {
        $key     = $escaper->escape($ticket['key'] ?? '?');
        $summary = $escaper->escape($ticket['summary'] ?? '');
        $reason  = $escaper->escape($rule['reason'] ?? 'Matched a custom rule');
        $link    = ($ticket['url'] ?? null) ? "<{$escaper->escape($ticket['url'])}|{$key}>" : $key;

        return ":bell: *{$reason}:* {$link} — {$summary}";
    }

    /** Fires only once retries are exhausted — keeps Laravel's retry/backoff contract intact. */
    public function failed(Throwable $exception): void
    {
        Log::warning('custom-notify dispatch failed permanently', [
            'user_id'     => $this->userId,
            'snapshot_id' => $this->snapshotId,
            'error'       => $exception->getMessage(),
        ]);
    }
}
