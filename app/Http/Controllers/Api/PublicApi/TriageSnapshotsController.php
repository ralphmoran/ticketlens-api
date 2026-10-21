<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Models\TriageSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TriageSnapshotsController
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT     = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile' => ['sometimes', 'string', 'max:100'],
            'limit'   => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $snapshots = TriageSnapshot::where('user_id', $request->user()->id)
            ->when(isset($data['profile']), fn ($q) => $q->where('profile', $data['profile']))
            ->latest('captured_at')
            ->limit($data['limit'] ?? self::DEFAULT_LIMIT)
            ->get();

        // Explicit allow-list: share tokens and license hashes never leave the server.
        return response()->json([
            'snapshots' => $snapshots->map(fn (TriageSnapshot $s) => [
                'id'           => $s->id,
                'profile'      => $s->profile,
                'ticket_count' => $s->ticket_count,
                'captured_at'  => $s->captured_at->toIso8601String(),
                'tickets'      => $s->tickets,
            ]),
        ]);
    }
}
