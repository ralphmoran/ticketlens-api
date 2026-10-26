<?php

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use App\Models\ApiKey;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Support\Str;

class WebhookDispatcher
{
    /** Metadata only: the integrator pulls ticket content through GET /v1/public/triage/snapshots. */
    public function triagePushed(User $user, TriageSnapshot $snapshot): void
    {
        if ($user->suspended_at || ! ApiKey::entitles($user)) {
            return;
        }

        $data = [
            'snapshot_id'  => $snapshot->id,
            'profile'      => $snapshot->profile,
            'ticket_count' => $snapshot->ticket_count,
            'captured_at'  => $snapshot->captured_at->toIso8601String(),
        ];

        $user->webhookEndpoints()->active()->pluck('id')->each(
            fn (int $id) => DeliverWebhookJob::dispatch($id, 'triage.pushed', $data, (string) Str::uuid()),
        );
    }
}
