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

        $cliToken = $user->cliTokens()->latest()->first();

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
            'has_anthropic_key' => !empty($user->anthropic_key),
            'has_openai_key'    => !empty($user->openai_key),
            'cli_token' => $cliToken ? [
                'name'         => $cliToken->name,
                'last_used_at' => $cliToken->last_used_at?->toDateTimeString(),
                'created_at'   => $cliToken->created_at->toDateTimeString(),
            ] : null,
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

    public function updateKeys(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'anthropic_key' => ['nullable', 'string', 'max:200'],
            'openai_key'    => ['nullable', 'string', 'max:200'],
        ]);

        $user = $request->user();

        $user->anthropic_key = $validated['anthropic_key'] !== '' ? ($validated['anthropic_key'] ?? null) : null;
        $user->openai_key    = $validated['openai_key'] !== '' ? ($validated['openai_key'] ?? null) : null;

        $user->save();

        return redirect()->back()->with('success', 'API keys updated.');
    }
}
