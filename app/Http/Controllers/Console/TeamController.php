<?php

namespace App\Http\Controllers\Console;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $groups = $user->groups()->with('users:id,name,email')->get(['groups.id', 'groups.name', 'groups.permissions']);

        return Inertia::render('Console/Team', [
            'groups' => $groups->map(fn($g) => [
                'id'          => $g->id,
                'name'        => $g->name,
                'permissions' => $g->permissions,
                'members'     => $g->users->map(fn($u) => [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                ]),
            ]),
        ]);
    }
}
