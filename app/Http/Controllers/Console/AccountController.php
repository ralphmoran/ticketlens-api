<?php

namespace App\Http\Controllers\Console;

use App\Models\CliToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
                'phone'   => $user->phone,
                'tier'    => $user->tier,
                'license' => $license ? [
                    'status'     => $license->status,
                    'expires_at' => $license->expires_at?->toDateString(),
                ] : null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $request->user()->update([
            'name'  => $data['name'],
            'phone' => $data['phone'],
        ]);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', function ($attribute, $value, $fail) use ($request) {
                if (! Hash::check($value, $request->user()->password)) {
                    $fail('Current password is incorrect.');
                }
            }],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $request->user()->update([
            'password' => Hash::make($data['password']),
        ]);

        // Kills any other active session (stolen cookie, shared device, old tab) —
        // the scenario this feature exists for: "I think someone else has access."
        Auth::logoutOtherDevices($data['password']);

        return back()->with('success', 'Password changed.');
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
