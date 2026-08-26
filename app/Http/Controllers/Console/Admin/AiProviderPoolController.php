<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActiveGroupResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderPoolController extends Controller
{
    public function __construct(private readonly ActiveGroupResolver $groupResolver) {}

    public function index(Request $request): Response
    {
        $group = $this->groupResolver->forRequest($request);
        $pools = $group ? $group->aiProviderPools()->get()->map(fn($p) => $p->toDisplayArray()) : collect();

        return Inertia::render('Console/Admin/AiProviderPool', [
            'providers' => $pools,
        ]);
    }
}
