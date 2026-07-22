<?php

namespace App\Services;

use App\Models\Group;
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
    private const INVITES_ITEM_LIMIT          = 20;
    private const LICENSE_EXPIRY_WARNING_DAYS = 14;

    public function __construct(private readonly PermissionService $permissions) {}

    public function pendingFor(User $user): array
    {
        $group   = $this->managerOwnedGroup($user);
        $recall  = $this->recallCategory($group);
        $license = $this->licenseCategory($user);
        $invites = $this->invitesCategory($group);

        return [
            'count' => ($recall['count'] ?? 0) + ($license['triggered'] ? 1 : 0) + ($invites['count'] ?? 0),
            'categories' => [
                'recall'           => $recall,
                'license'          => $license,
                'invites'          => $invites,
                'workflowFailures' => ['available' => false, 'comingSoon' => true],
            ],
        ];
    }

    /**
     * Shared gate for recall/invites — both are manager-only concepts, gated
     * identically, so the effective-permissions query and ownedGroup lookup
     * run once per request instead of once per category.
     */
    private function managerOwnedGroup(User $user): ?Group
    {
        $effectivePermissions = $this->permissions->effective($user);
        if (! $this->permissions->isEffectiveTeamManager($user, $effectivePermissions)) {
            return null;
        }

        return $user->ownedGroup;
    }

    private function recallCategory(?Group $group): ?array
    {
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

    private function invitesCategory(?Group $group): ?array
    {
        if ($group === null) {
            return null;
        }

        $pending = $group->members()
            ->whereNotNull('users.invited_at')
            ->whereNull('users.activated_at');

        return [
            'available' => true,
            'count'     => (clone $pending)->count(),
            'items'     => $pending->orderByDesc('users.invited_at')
                ->limit(self::INVITES_ITEM_LIMIT)
                ->get(['users.id', 'users.name', 'users.email', 'users.invited_at'])
                ->map(fn (User $member) => [
                    'id'         => $member->id,
                    'name'       => $member->name,
                    'email'      => $member->email,
                    'invited_at' => $member->invited_at->toIso8601String(),
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
