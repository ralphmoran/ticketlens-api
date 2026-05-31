<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\BriefTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BriefTemplatesController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $group = $user->is_owner ? null : $user->groups()->first();

        $templates = BriefTemplate::forGroup($group?->id)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return Inertia::render('Console/Admin/Templates/Index', [
            'templates' => $templates,
            'tier'      => $user->tier,
            'canManage' => ! in_array($user->tier, ['free'], true) || $user->is_owner,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->tier === 'free' && ! $user->is_owner, 403, 'Custom templates require a Pro or higher plan.');

        $group = $user->is_owner ? null : $user->groups()->first();
        abort_unless($group !== null || $user->is_owner, 403, 'Custom templates require a Team plan.');

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'sections'    => ['required', 'array'],
        ]);

        $slug = $this->uniqueSlug($data['name'], $group?->id);

        BriefTemplate::create([
            'group_id'    => $group?->id,
            'slug'        => $slug,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'sections'    => $data['sections'],
            'is_system'   => false,
            'created_by'  => $user->id,
        ]);

        return redirect()->route('console.admin.templates.index');
    }

    public function update(Request $request, BriefTemplate $briefTemplate): RedirectResponse
    {
        $user = $request->user();
        abort_if($briefTemplate->is_system, 403, 'System templates cannot be modified.');
        abort_unless($user->is_owner || $briefTemplate->group_id === $user->groups()->first()?->id, 403);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'sections'    => ['required', 'array'],
        ]);

        $briefTemplate->update([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'sections'    => $data['sections'],
        ]);

        return redirect()->route('console.admin.templates.index');
    }

    public function destroy(Request $request, BriefTemplate $briefTemplate): RedirectResponse
    {
        $user = $request->user();
        abort_if($briefTemplate->is_system, 403, 'System templates cannot be deleted.');
        abort_unless($user->is_owner || $briefTemplate->group_id === $user->groups()->first()?->id, 403);

        $briefTemplate->delete();

        return redirect()->route('console.admin.templates.index');
    }

    private function uniqueSlug(string $name, ?int $groupId): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = $base;
        $i    = 1;
        while (BriefTemplate::where('slug', $slug)->where('group_id', $groupId)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }
}
