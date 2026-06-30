<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(private readonly TierService $tiers) {}

    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        if (Auth::user()->suspended_at !== null) {
            $this->forgetSession($request);

            return redirect()->route('console.suspended');
        }

        if (! Auth::user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        $destination = Auth::user()->is_owner
            ? route('console.owner.dashboard')
            : route('console.dashboard');

        return redirect()->intended($destination);
    }

    public function register(Request $request): RedirectResponse
    {
        $v = Validator::make($request->all(), [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if ($v->fails()) {
            return redirect()->route('console.login')
                ->withErrors($v)
                ->withInput($request->except('password', 'password_confirmation'));
        }

        $validated = $v->validated();

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => $validated['password'],
        ]);
        $user->tier = 'free';
        $user->save();

        try {
            $this->tiers->syncUser($user);
        } catch (\Throwable $e) {
            $user->forceDelete();
            throw $e;
        }

        Auth::login($user);
        $request->session()->regenerate();

        $user->sendEmailVerificationNotification();

        return redirect()->route('verification.notice');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->forgetSession($request);

        return redirect()->route('console.login');
    }

    /**
     * Log the user out and fully tear down the session — invalidate it and
     * issue a fresh CSRF token so no authenticated state survives the redirect.
     */
    private function forgetSession(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
