<?php

namespace App\Http\Controllers\Api\Triage;

use App\Enums\Permission;
use App\Http\Requests\Triage\PushRequest;
use App\Models\License;
use App\Models\TriageSnapshot;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShareController
{
    public function __invoke(PushRequest $request): JsonResponse
    {
        $keyHash = TriageSnapshot::hashKey($request->bearerToken());
        $tickets = $request->validated('tickets');

        $userId = License::where('lemon_key_hash', $keyHash)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->value('user_id');

        if ($userId !== null) {
            $user = User::find($userId);
            if ($user && ($user->permissions & Permission::AttentionQueue->value) === 0) {
                return response()->json(['error' => 'Insufficient permissions'], 403);
            }
        }

        $expiresAt = now()->addHours(24);
        $snapshot  = null;
        $attempts  = 0;

        while ($snapshot === null) {
            try {
                $snapshot = TriageSnapshot::updateOrCreate(
                    ['license_key_hash' => $keyHash, 'profile' => $request->validated('profile')],
                    [
                        'user_id'          => $userId,
                        'tickets'          => $tickets,
                        'ticket_count'     => count($tickets),
                        'captured_at'      => $request->validated('captured_at'),
                        'share_token'      => TriageSnapshot::generateToken(),
                        'share_expires_at' => $expiresAt,
                    ],
                );
            } catch (UniqueConstraintViolationException $e) {
                // Retry on share_token unique collision (astronomically rare with UUID4).
                if (++$attempts >= 3) {
                    throw $e;
                }
            }
        }

        $shareUrl = self::shareUrl($snapshot->share_token);

        return response()->json([
            'url'        => $shareUrl,
            'expires_at' => $snapshot->share_expires_at->toIso8601String(),
        ]);
    }

    public static function shareUrl(string $token): string
    {
        $base = rtrim(config('app.url'), '/');
        return "{$base}/s/{$token}";
    }
}
