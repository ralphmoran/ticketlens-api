<?php

namespace App\Http\Controllers\Console;

use App\Models\CliToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CliAuthController
{
    public function show(Request $request): View|RedirectResponse
    {
        $port     = (int) $request->query('port', 0);
        $state    = (string) $request->query('state', '');
        $hostname = (string) $request->query('hostname', '') ?: null;

        if ($port < 1024 || $port > 65535 || strlen($state) < 8) {
            abort(400, 'Invalid authorization request — missing or malformed parameters.');
        }

        $expectedEmail = (string) $request->query('email', '');

        $emailMismatch = $expectedEmail !== ''
            && strtolower($request->user()->email) !== strtolower($expectedEmail);

        // fresh=1 is sent by the CLI on every login attempt. When no expected email is
        // known (free tier, no license), force re-auth so the CLI never silently inherits
        // a pre-existing browser session for a different user (e.g. the owner console).
        $forceFresh = $expectedEmail === '' && $request->boolean('fresh');

        if ($emailMismatch || $forceFresh) {
            $params = array_filter(['port' => $port, 'state' => $state, 'hostname' => $hostname]);
            return redirect()->route('console.auth.cli.switch', $params);
        }

        return view('console.cli-authorize', [
            'port'     => $port,
            'state'    => $state,
            'hostname' => $hostname,
            'userName' => $request->user()->name,
        ]);
    }

    public function switchAccount(Request $request): RedirectResponse
    {
        $port     = (int) $request->query('port', 0);
        $state    = (string) $request->query('state', '');
        $hostname = (string) $request->query('hostname', '') ?: null;

        if ($port < 1024 || $port > 65535 || strlen($state) < 8) {
            abort(400);
        }

        $params      = array_filter(['port' => $port, 'state' => $state, 'hostname' => $hostname]);
        $intendedUrl = url('/console/auth/cli') . '?' . http_build_query($params);

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('url.intended', $intendedUrl);

        return redirect()->route('console.login');
    }

    public function authorize(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'port'     => ['required', 'integer', 'min:1024', 'max:65535'],
            'state'    => ['required', 'string', 'min:8'],
            'hostname' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $user->cliTokens()->delete();

        $plaintext = 'tl_' . Str::random(40);

        CliToken::create([
            'user_id'      => $user->id,
            'name'         => 'CLI (Browser Login)',
            'token_hash'   => CliToken::hashToken($plaintext),
            'token_prefix' => substr($plaintext, 0, 8),
        ]);

        $callbackUrl = 'http://localhost:' . $validated['port']
            . '/callback?token=' . urlencode($plaintext)
            . '&state=' . urlencode($validated['state']);

        return redirect()->away($callbackUrl);
    }
}
