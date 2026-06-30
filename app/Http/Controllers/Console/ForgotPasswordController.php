<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function send(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Password::sendResetLink(['email' => $request->email]);

        return redirect()->route('console.login')
            ->with('status', 'If that email address is registered, we sent a reset link.');
    }
}
