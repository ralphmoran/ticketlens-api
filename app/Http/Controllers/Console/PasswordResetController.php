<?php

namespace App\Http\Controllers\Console;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetController
{
    public function show(Request $request, string $token): View
    {
        return view('console.set-password', [
            'token'       => $token,
            'email'       => $request->string('email')->value(),
            'authWarning' => auth()->check() ? auth()->user()->email : null,
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->setRememberToken(Str::random(60));
                // Only the first-ever reset counts as activation — an ordinary
                // forgot-password reset by an already-active user must not
                // touch this, or invite-pending tracking silently corrupts.
                if ($user->activated_at === null) {
                    $user->activated_at = now();
                }
                $user->save();
                Auth::login($user);
                request()->session()->regenerate();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('console.dashboard');
        }

        return back()->withErrors(['email' => 'The invitation link is invalid or has expired.']);
    }
}
