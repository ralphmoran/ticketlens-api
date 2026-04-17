<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\User;
use App\Models\UserFeatureGrant;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GrantController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function store(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'feature_id' => ['required', 'integer', 'exists:features,id'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'note'       => ['nullable', 'string', 'max:255'],
        ]);

        $grant = UserFeatureGrant::create([
            'user_id'    => $user->id,
            'feature_id' => $validated['feature_id'],
            'granted_by' => $request->user()->id,
            'expires_at' => $validated['expires_at'] ?? null,
            'note'       => $validated['note'] ?? null,
        ]);

        $this->audit->logFromRequest($request, 'grant.created', $user, $grant->feature_id);

        return back();
    }

    public function destroy(Request $request, User $user, int $grantId): RedirectResponse
    {
        $grant = UserFeatureGrant::where('id', $grantId)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->firstOrFail();

        UserFeatureGrant::where('id', $grant->id)->update(['revoked_at' => now()]);

        $this->audit->logFromRequest($request, 'grant.revoked', $user, $grant->feature_id);

        return back();
    }
}
