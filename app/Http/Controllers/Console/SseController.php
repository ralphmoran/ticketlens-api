<?php

namespace App\Http\Controllers\Console;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController
{
    public function stream(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user->is_owner || in_array($user->tier, ['pro', 'team'], true),
            403,
        );

        $group = $user->ownedGroup ?? $user->groups()->first();
        abort_unless($group !== null, 403, 'No group.');

        $groupId   = $group->id;
        $streamKey = "ticketlens:events:{$groupId}";
        $rawId  = $request->header('Last-Event-ID', '');
        $cursor = preg_match('/^\d+-\d+$/', $rawId) ? $rawId : '$';

        // Release session lock — SSE streams block indefinitely; holding the
        // session lock would stall every other request from the same browser tab.
        $request->session()->save();

        return response()->stream(function () use ($streamKey, $cursor): void {
            set_time_limit(0);

            // Immediate flush so the browser sees the connection as open
            // before the first XREAD block (otherwise looks stalled for ~5s).
            echo ": connected\n\n";
            ob_flush();
            flush();

            $allowed = ['rule.changed', 'triage.pushed'];

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                // phpredis xread: (streams_array, count, block_ms)
                // Returns false|[] on timeout, ['key' => [[$id, $fields], ...]] on messages
                $result = Redis::xread([$streamKey => $cursor], 10, 5000);

                if (empty($result)) {
                    echo ": heartbeat\n\n";
                    ob_flush();
                    flush();
                    continue;
                }

                // phpredis keys results by the prefixed stream name; use array_values()
                // so the lookup works regardless of any configured key prefix.
                // Entries are associative ['id' => ['field' => 'value']] — not tuples.
                foreach (array_values($result)[0] ?? [] as $id => $fields) {
                    $cursor = $id;
                    $type   = $fields['type'] ?? '';
                    if (! in_array($type, $allowed, true)) {
                        continue;
                    }
                    echo "id: {$id}\n";
                    echo "event: {$type}\n";
                    echo "data: {$fields['payload']}\n\n";
                    ob_flush();
                    flush();
                }
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
