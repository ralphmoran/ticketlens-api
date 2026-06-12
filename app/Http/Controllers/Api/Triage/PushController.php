<?php

namespace App\Http\Controllers\Api\Triage;

use App\Enums\Permission;
use App\Http\Requests\Triage\PushRequest;
use App\Jobs\EvaluateAlertsJob;
use App\Models\TriageSnapshot;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PushController
{
    public function __invoke(PushRequest $request): JsonResponse
    {
        $user       = $request->user();
        $tickets    = $request->validated('tickets');
        $profile    = $request->validated('profile');
        $capturedAt = $request->validated('captured_at');

        if (($user->permissions & Permission::AttentionQueue->value) === 0) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
        }

        $ticketCount = count($tickets);

        $data = [
            'tickets'      => $tickets,
            'ticket_count' => $ticketCount,
            'captured_at'  => $capturedAt,
        ];

        if ($request->has('git_branches')) {
            $data['git_branches'] = $request->validated('git_branches');
        }

        if ($request->has('cli_activity')) {
            $data['cli_activity'] = $request->validated('cli_activity');
        }

        $capturedDate = Carbon::parse($capturedAt)->utc()->toDateString();

        // One row per user+profile+day: update if same day already exists, else create.
        $existing = TriageSnapshot::where('user_id', $user->id)
            ->where('profile', $profile)
            ->whereDate('captured_at', $capturedDate)
            ->latest('captured_at')
            ->first();

        // Prune rows older than 90 days on every push (not only on insert) so
        // all active profiles are cleaned up regardless of daily push frequency.
        TriageSnapshot::where('user_id', $user->id)
            ->where('profile', $profile)
            ->where('captured_at', '<', now()->subDays(90))
            ->delete();

        if ($existing) {
            $existing->update($data);
            $snapshot = $existing;
        } else {
            $snapshot = TriageSnapshot::create(array_merge(
                ['user_id' => $user->id, 'profile' => $profile],
                $data,
            ));
        }

        EvaluateAlertsJob::dispatch($user->id, $snapshot->id);

        $commands = $request->input('cli_activity.commands', []);
        if (!empty($commands)) {
            $now  = now();
            $rows = [];
            foreach ($commands as $cmd => $counters) {
                $flags = array_filter(
                    $counters,
                    fn ($v, $k) => $k !== 'count' && str_starts_with($k, '-'),
                    ARRAY_FILTER_USE_BOTH,
                );
                $rows[] = [
                    'user_id'      => $user->id,
                    'action'       => $cmd,
                    'ticket_key'   => null,
                    'tokens_used'  => (int) ($counters['tokens_saved'] ?? 0),
                    'metadata'     => json_encode([
                        'count' => $counters['count'] ?? 0,
                        'flags' => $flags,
                    ]),
                    'created_at'   => $now,
                ];
            }
            DB::table('usage_logs')->insert($rows);
        }

        return response()->json(['pushed' => true, 'ticket_count' => $ticketCount]);
    }
}
