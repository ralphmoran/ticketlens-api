<?php

namespace App\Services;

use App\Models\RecallNote;
use App\Models\User;

/**
 * Aggregates the items a user needs to act on into one bell-menu payload.
 * Each category is independently gated — a category the user can't see
 * returns null/unavailable rather than an empty-but-present list, so the
 * frontend can distinguish "nothing pending" from "not applicable to you".
 */
class NotificationService
{
    private const RECALL_ITEM_LIMIT           = 20;
    private const LICENSE_EXPIRY_WARNING_DAYS = 14;

    public function __construct(private readonly PermissionService $permissions) {}

    public function pendingFor(User $user): array
    {
        $recall  = $this->recallCategory($user);
        $license = $this->licenseCategory($user);

        return [
            'count' => ($recall['count'] ?? 0) + ($license['triggered'] ? 1 : 0),
            'categories' => [
                'recall'           => $recall,
                'license'          => $license,
                'invites'          => ['available' => false, 'comingSoon' => true],
                'workflowFailures' => ['available' => false, 'comingSoon' => true],
            ],
        ];
    }

    /**
     * Gate is checked BEFORE the query runs, not after — a non-manager must
     * never reach the RecallNote lookup at all, matching how the `team.manager`
     * route middleware already excludes them from /console/admin/recall itself.
     */
    private function recallCategory(User $user): ?array
    {
        $effectivePermissions = $this->permissions->effective($user);
        if (! $this->permissions->isEffectiveTeamManager($user, $effectivePermissions)) {
            return null;
        }

        $group = $user->ownedGroup;
        if ($group === null) {
            return null;
        }

        $unverified = RecallNote::where('group_id', $group->id)->where('status', 'unverified');

        return [
            'available' => true,
            'count'     => (clone $unverified)->count(),
            'items'     => $unverified->orderByDesc('created_at')
                ->limit(self::RECALL_ITEM_LIMIT)
                ->get(['id', 'title', 'created_at'])
                ->map(fn (RecallNote $note) => [
                    'id'         => $note->id,
                    'title'      => $note->title,
                    'created_at' => $note->created_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    private function licenseCategory(User $user): array
    {
        $license = $user->license;
        if ($license === null) {
            return ['available' => false, 'triggered' => false];
        }

        $expiringSoon = $license->expires_at !== null
            && $license->expires_at->isFuture()
            && $license->expires_at->lte(now()->addDays(self::LICENSE_EXPIRY_WARNING_DAYS));

        return [
            'available'  => true,
            'triggered'  => ! $license->isActive() || $expiringSoon,
            'status'     => $license->status,
            'expires_at' => $license->expires_at?->toIso8601String(),
        ];
    }
}
