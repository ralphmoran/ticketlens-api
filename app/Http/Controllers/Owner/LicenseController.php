<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\User;
use App\Services\LicenseIssuanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class LicenseController extends Controller
{
    private const VALID_TIERS = ['pro', 'team', 'enterprise'];

    public function __construct(private readonly LicenseIssuanceService $issuance) {}

    public function index(Request $request): Response
    {
        $query = License::with(['user:id,name,email', 'issuedBy:id,name,email'])
            ->orderBy('created_at', 'desc');

        if ($source = $request->string('source')->value()) {
            $source === 'owner_issued'
                ? $query->whereNotNull('issued_by_user_id')
                : $query->whereNull('issued_by_user_id');
        }

        if ($tier = $request->string('tier')->value()) {
            $query->where('tier', $tier);
        }

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return Inertia::render('Console/Owner/Licenses/Index', [
            'licenses' => $query->paginate(25)->withQueryString(),
            'filters'  => $request->only('source', 'tier', 'status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Console/Owner/Licenses/Create', [
            // Exclude the owner row from the recipient picker — issuing a license
            // to the owner would route through bootstrapTeamGroup() and overwrite
            // the owner sentinel tier/permissions.
            'clients' => User::query()
                ->where('is_owner', false)
                ->whereNull('deleted_at')
                ->orderBy('email')
                ->limit(500)
                ->get(['id', 'name', 'email', 'tier']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id'    => ['required', 'integer', 'exists:users,id'],
            'tier'       => ['required', 'string', 'in:' . implode(',', self::VALID_TIERS)],
            'seats'      => ['nullable', 'integer', 'min:1', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'send_email' => ['sometimes', 'boolean'],
        ]);

        $recipient = User::findOrFail($validated['user_id']);

        // Defence in depth: the create() endpoint already filters owner out of
        // the picker, but the store endpoint is reachable directly so it must
        // also reject the owner row.
        abort_if($recipient->is_owner, 422, 'Cannot issue a license to the platform owner.');

        $result = $this->issuance->issue(
            owner: $request->user(),
            recipient: $recipient,
            tier: $validated['tier'],
            expiresAt: isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
            seats: $validated['seats'] ?? null,
            sendEmail: (bool) ($validated['send_email'] ?? true),
        );

        // Flash raw key for one-time reveal — NEVER persisted.
        return redirect()
            ->route('console.owner.licenses.created', $result['license'])
            ->with('raw_key', $result['raw_key'])
            ->with('emailed', (bool) ($validated['send_email'] ?? true));
    }

    public function created(Request $request, License $license): Response
    {
        $rawKey  = session('raw_key');
        $emailed = session('emailed', false);

        abort_if($rawKey === null, 410, 'Raw key reveal already consumed.');

        return Inertia::render('Console/Owner/Licenses/Created', [
            'license'  => $license->load('user:id,name,email'),
            'raw_key'  => $rawKey,
            'emailed'  => $emailed,
        ]);
    }

    public function destroy(Request $request, License $license): RedirectResponse
    {
        $this->issuance->revoke($request->user(), $license);

        return back();
    }
}
