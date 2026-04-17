<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\User;
use App\Models\UserFeatureGrant;
use App\Services\AuditService;
use App\Services\TierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const VALID_TIERS = ['free', 'pro', 'team', 'enterprise'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly TierService $tiers,
    ) {}

    public function index(Request $request): Response
    {
        $query = User::query()->withTrashed();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($tier = $request->string('tier')->trim()->value()) {
            $query->where('tier', $tier);
        }

        return Inertia::render('Console/Owner/Users/Index', [
            'users'   => $query->latest()->paginate(20)->withQueryString(),
            'filters' => $request->only('search', 'tier'),
        ]);
    }

    public function show(User $user): Response
    {
        return Inertia::render('Console/Owner/Users/Show', [
            'user'     => $user,
            'features' => Feature::orderBy('sort_order')->get(['id', 'label']),
            'grants'   => UserFeatureGrant::where('user_id', $user->id)
                ->active()
                ->with('feature:id,label')
                ->latest('created_at')
                ->get(),
            'logs'     => \App\Models\AuditLog::where('target_user_id', $user->id)
                ->latest()
                ->limit(50)
                ->with('actor')
                ->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'tier' => ['required', 'string', 'in:' . implode(',', self::VALID_TIERS)],
        ]);

        $oldTier = $user->tier;

        $user->update(['tier' => $validated['tier']]);
        $this->tiers->syncUser($user->fresh());

        $this->audit->logFromRequest($request, 'user.tier_changed', $user, $oldTier, $validated['tier']);

        return back();
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $user->update(['suspended_at' => now()]);

        $this->audit->logFromRequest($request, 'user.suspended', $user);

        return back();
    }

    public function restore(Request $request, User $user): RedirectResponse
    {
        $user->update(['suspended_at' => null]);

        $this->audit->logFromRequest($request, 'user.restored', $user);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            abort(422, 'Cannot delete your own account.');
        }

        $this->audit->logFromRequest($request, 'user.deleted', $user);

        $user->delete(); // SoftDeletes

        return back();
    }
}
