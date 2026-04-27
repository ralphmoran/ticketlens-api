<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is_owner, 403, 'The platform owner account cannot be impersonated.');

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['target' => 'You cannot impersonate yourself.']);
        }

        $this->impersonation->start($request->user(), $user, $request);

        return redirect()->route('console.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Route is behind `auth` only (the `owner` middleware would lock out an owner
        // currently authed as their target). Defense-in-depth: a user with no active
        // impersonation has no business hitting this endpoint.
        abort_unless(
            $request->session()->has(ImpersonationService::SESSION_KEY),
            403,
        );

        $stopped = $this->impersonation->stop($request);

        if ($stopped === null) {
            return redirect()->route('console.dashboard');
        }

        return redirect()->route('console.owner.clients.show', $stopped->target_user_id);
    }
}
