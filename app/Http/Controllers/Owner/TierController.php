<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Services\AuditService;
use App\Services\TierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TierController extends Controller
{
    private const VALID_TIERS = ['free', 'pro', 'team', 'enterprise'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly TierService $tiers,
    ) {}

    public function index(): Response
    {
        $features = Feature::orderBy('sort_order')->get();

        $matrix = collect(self::VALID_TIERS)->mapWithKeys(function (string $tier) {
            $featureIds = DB::table('tier_features')
                ->where('tier', $tier)
                ->pluck('feature_id')
                ->all();

            return [$tier => $featureIds];
        });

        return Inertia::render('Console/Owner/Tiers/Index', [
            'tiers'    => self::VALID_TIERS,
            'features' => $features,
            'matrix'   => $matrix,
        ]);
    }

    public function addFeature(Request $request, string $tier): RedirectResponse
    {
        if (! in_array($tier, self::VALID_TIERS, true)) {
            abort(404);
        }

        $validated = $request->validate([
            'feature_id' => ['required', 'integer', 'exists:features,id'],
        ]);

        DB::table('tier_features')->insertOrIgnore([
            'tier'       => $tier,
            'feature_id' => $validated['feature_id'],
        ]);

        $this->tiers->syncAllForTier($tier);

        $this->audit->logFromRequest($request, 'tier.feature_added', metadata: [
            'tier'       => $tier,
            'feature_id' => $validated['feature_id'],
        ]);

        return back();
    }

    public function removeFeature(Request $request, string $tier, Feature $feature): RedirectResponse
    {
        if (! in_array($tier, self::VALID_TIERS, true)) {
            abort(404);
        }

        DB::table('tier_features')
            ->where('tier', $tier)
            ->where('feature_id', $feature->id)
            ->delete();

        $this->tiers->syncAllForTier($tier);

        $this->audit->logFromRequest($request, 'tier.feature_removed', metadata: [
            'tier'       => $tier,
            'feature_id' => $feature->id,
        ]);

        return back();
    }
}
