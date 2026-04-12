<?php

namespace App\Http\Controllers\Console;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UpgradeController
{
    public function index(Request $request): Response
    {
        $requiredTier = $request->query('tier', 'pro');

        return Inertia::render('Console/Upgrade', [
            'required_tier' => $requiredTier,
            'current_tier'  => $request->user()?->tier ?? 'free',
        ]);
    }
}
