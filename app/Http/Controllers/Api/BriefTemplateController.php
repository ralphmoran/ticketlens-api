<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BriefTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefTemplateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user  = $request->user();
        $group = $user->is_owner ? null : $user->groups()->first();

        $templates = BriefTemplate::forGroup($group?->id)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return response()->json($templates);
    }
}
