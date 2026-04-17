<?php

namespace App\Jobs;

use App\Models\UserFeatureGrant;
use App\Services\AuditService;
use App\Services\TierService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RevokeExpiredGrantsJob implements ShouldQueue
{
    use Queueable;

    public function handle(AuditService $audit, TierService $tiers): void
    {
        // Only process grants that are not yet revoked but have expired
        $expired = UserFeatureGrant::whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('user')
            ->get();

        foreach ($expired as $grant) {
            UserFeatureGrant::where('id', $grant->id)->update(['revoked_at' => now()]);

            $tiers->syncUser($grant->user);

            $audit->log(
                actor:    null,
                action:   'grant.auto_revoked',
                target:   $grant->user,
                oldValue: $grant->feature_id,
                newValue: null,
            );
        }
    }
}
