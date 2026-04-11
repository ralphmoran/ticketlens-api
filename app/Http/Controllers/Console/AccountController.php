<?php

namespace App\Http\Controllers\Console;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController
{
    // TODO: implement BYOK key storage
    public function index(Request $request): Response
    {
        $user    = $request->user();
        $license = $user->license ?? null;

        return Inertia::render('Console/Account', [
            'account' => [
                'name'    => $user->name,
                'email'   => $user->email,
                'tier'    => $user->tier,
                'license' => $license ? [
                    'status'     => $license->status,
                    'expires_at' => $license->expires_at?->toDateString(),
                ] : null,
            ],
        ]);
    }
}
