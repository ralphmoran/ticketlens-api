<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditService
{
    public function log(
        ?User $actor,
        string $action,
        ?User $target = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_id'       => $actor?->id,
            'target_user_id' => $target?->id,
            'action'         => $action,
            'old_value'      => $oldValue !== null ? (is_array($oldValue) ? $oldValue : ['value' => $oldValue]) : null,
            'new_value'      => $newValue !== null ? (is_array($newValue) ? $newValue : ['value' => $newValue]) : null,
            'metadata'       => $metadata ?: null,
            'ip_address'     => $ipAddress,
        ]);
    }

    public function logFromRequest(
        Request $request,
        string $action,
        ?User $target = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        array $metadata = [],
    ): AuditLog {
        return $this->log(
            actor: $request->user(),
            action: $action,
            target: $target,
            oldValue: $oldValue,
            newValue: $newValue,
            metadata: $metadata,
            ipAddress: $request->ip(),
        );
    }
}
