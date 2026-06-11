<?php

namespace App\Http\Controllers\Console;

use App\Models\CliToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AccountController
{
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

    public function generateCliToken(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Revoke any existing tokens first
        $user->cliTokens()->delete();

        $plaintext = 'tl_' . Str::random(40);

        CliToken::create([
            'user_id'    => $user->id,
            'name'       => 'CLI Token',
            'token_hash' => CliToken::hashToken($plaintext),
        ]);

        // Flash plaintext once — never persisted
        return redirect()->back()->with('cli_token_generated', $plaintext);
    }

    public function revokeCliToken(Request $request): RedirectResponse
    {
        $request->user()->cliTokens()->delete();

        return redirect()->back()->with('success', 'CLI access token revoked.');
    }

}
