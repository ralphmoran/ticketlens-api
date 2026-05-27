<?php

namespace App\Http\Controllers\Api\Triage;

use App\Enums\Permission;
use App\Http\Requests\Triage\PushRequest;
use App\Models\TriageSnapshot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

class ShareController
{
    public function __invoke(PushRequest $request): JsonResponse
    {
        $user    = $request->user();
        $tickets = $request->validated('tickets');

        if (($user->permissions & Permission::AttentionQueue->value) === 0) {
            return response()->json(['error' => 'Insufficient permissions'], 403);
        }

        $expiresAt = now()->addHours(24);
        $snapshot  = null;
        $attempts  = 0;

        while ($snapshot === null) {
            try {
                $snapshot = TriageSnapshot::updateOrCreate(
                    ['user_id' => $user->id, 'profile' => $request->validated('profile')],
                    [
                        'tickets'          => $tickets,
                        'ticket_count'     => count($tickets),
                        'captured_at'      => $request->validated('captured_at'),
                        'share_token'      => TriageSnapshot::generateToken(),
                        'share_expires_at' => $expiresAt,
                    ],
                );
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempts >= 3) {
                    throw $e;
                }
            }
        }

        return response()->json([
            'url'        => self::shareUrl($snapshot->share_token),
            'expires_at' => $snapshot->share_expires_at->toIso8601String(),
        ]);
    }

    public static function shareUrl(string $token): string
    {
        $base = rtrim(config('app.url'), '/');
        return "{$base}/s/{$token}";
    }
}
