<?php

namespace App\Http\Controllers\Console;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'has_anthropic_key' => !empty($user->anthropic_key),
            'has_openai_key'    => !empty($user->openai_key),
        ]);
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
