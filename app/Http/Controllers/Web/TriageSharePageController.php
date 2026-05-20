<?php

namespace App\Http\Controllers\Web;

use App\Models\TriageSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TriageSharePageController
{
    public function __invoke(string $token): Response|\Illuminate\Http\RedirectResponse
    {
        $snapshot = TriageSnapshot::where('share_token', $token)
            ->where('share_expires_at', '>', now())
            ->first();

        if (! $snapshot) {
            abort(404);
        }

        return response()->view('share.triage', [
            'tickets'    => $snapshot->tickets ?? [],
            'profile'    => $snapshot->profile,
            'capturedAt' => $snapshot->captured_at,
            'expiresAt'  => $snapshot->share_expires_at,
        ]);
    }
}
