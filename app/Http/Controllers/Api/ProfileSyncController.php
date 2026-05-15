<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $profiles = $request->user()
            ->trackerProfiles()
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => $p->toCliArray())
            ->values();

        return response()->json(['profiles' => $profiles]);
    }
}
