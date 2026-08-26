<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProviderRole;
use App\Services\ActiveGroupResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderRoleController extends Controller
{
    public function __construct(private readonly ActiveGroupResolver $groupResolver) {}

    public function index(Request $request): Response
    {
        $group = $this->groupResolver->forRequest($request);
        $pool  = $group ? $group->aiProviderPools()->where('enabled', true)->get()->map(fn($p) => $p->toDisplayArray()) : collect();
        $roles = $request->user()->aiProviderRoles()->with('providers')->get()->map(fn($r) => $r->toDisplayArray());

        return Inertia::render('Console/Admin/AiProviderRoles', [
            'pool'  => $pool,
            'roles' => $roles,
            'kinds' => AiProviderRole::KINDS,
        ]);
    }
}
