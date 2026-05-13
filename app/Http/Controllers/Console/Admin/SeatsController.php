<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeatsController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $manager = $request->user();
        $group   = $manager->ownedGroup;

        if ($group === null) {
            return redirect('/console/owner/licenses');
        }

        $license = License::where('user_id', $manager->id)
            ->where('status', 'active')
            ->latest()
            ->first();

        $seatsUsed = $group->members()->count();

        return Inertia::render('Console/Admin/Seats', [
            'license' => $license ? [
                'id'         => $license->id,
                'tier'       => $license->tier,
                'seats'      => $license->seats,
                'status'     => $license->status,
                'expires_at' => $license->expires_at,
            ] : null,
            'seats_used' => $seatsUsed,
            'group'      => ['id' => $group->id, 'name' => $group->name],
        ]);
    }
}
