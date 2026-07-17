<?php

namespace App\Http\Controllers\Owner;

use App\Exceptions\InsufficientSeats;
use App\Exceptions\InvalidGrantRecipient;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TeamAccessController extends Controller
{
    public function __construct(private readonly TeamAccessService $teamAccess) {}

    public function store(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'seats'      => ['required', 'integer', 'min:2', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $expiresAt = isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;

        try {
            $this->teamAccess->grant($request->user(), $user, $validated['seats'], $expiresAt);
        } catch (InvalidGrantRecipient|InsufficientSeats $e) {
            return back()->withErrors(['seats' => $e->getMessage()]);
        }

        return redirect()->route('console.owner.clients.show', $user);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->teamAccess->revoke($request->user(), $user);

        return redirect()->route('console.owner.clients.show', $user);
    }
}
