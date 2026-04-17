<?php

namespace App\Services;

use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImpersonationService
{
    public const SESSION_KEY = 'impersonator_id';

    public function __construct(private readonly AuditService $audit) {}

    public function start(User $owner, User $target, Request $request): ImpersonationSession
    {
        // DB writes first, in one transaction. Session/auth side-effects happen only
        // after the transaction commits, so a DB failure can never leave the PHP
        // session authed as the target with no matching row.
        $session = DB::transaction(function () use ($owner, $target, $request) {
            // Defensive: close any open session this actor already has (double-submit,
            // crashed prior stop). Prevents orphaned rows with ended_at=null.
            ImpersonationSession::where('actor_id', $owner->id)
                ->active()
                ->update(['ended_at' => now()]);

            $new = ImpersonationSession::create([
                'actor_id'       => $owner->id,
                'target_user_id' => $target->id,
                'started_at'     => now(),
                'ip_address'     => $request->ip(),
            ]);

            $this->audit->log(
                actor: $owner,
                action: 'impersonation.started',
                target: $target,
                metadata: ['session_id' => $new->id],
                ipAddress: $request->ip(),
            );

            return $new;
        });

        $request->session()->put(self::SESSION_KEY, $owner->id);
        Auth::login($target);
        // Rotate session ID to prevent fixation — any pre-impersonation session ID
        // (known to an attacker or to the owner's previous browsing context) cannot
        // be replayed to hijack the target's session.
        $request->session()->regenerate();

        return $session;
    }

    public function stop(Request $request): ?ImpersonationSession
    {
        $ownerId = $request->session()->get(self::SESSION_KEY);
        if ($ownerId === null) {
            return null;
        }

        $owner = User::find($ownerId);
        if ($owner === null) {
            $request->session()->forget(self::SESSION_KEY);
            return null;
        }

        $target = Auth::user();

        $session = DB::transaction(function () use ($owner, $target) {
            $row = ImpersonationSession::where('actor_id', $owner->id)
                ->active()
                ->latest('id')
                ->first();

            $row?->update(['ended_at' => now()]);

            $this->audit->log(
                actor: $owner,
                action: 'impersonation.stopped',
                target: $target,
                metadata: $row ? ['session_id' => $row->id] : [],
                ipAddress: request()->ip(),
            );

            return $row;
        });

        $request->session()->forget(self::SESSION_KEY);
        Auth::login($owner);
        $request->session()->regenerate();

        return $session;
    }
}
