<?php

namespace App\Http\Controllers\Console;

use App\Models\License;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin section — Stage 4 will rescope to Team-manager view.
 * For Phase 1, only Licenses remains here (as a read-only list).
 */
class AdminController
{
    public function licenses(): Response
    {
        $licenses = License::with(['user:id,email'])
            ->orderBy('created_at', 'desc')
            ->paginate(25, ['id', 'user_id', 'tier', 'status', 'expires_at', 'created_at']);

        return Inertia::render('Console/Admin/Licenses', [
            'licenses' => $licenses,
        ]);
    }
}
