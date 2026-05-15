<?php

namespace App\Http\Controllers\Console;

use App\Models\TrackerProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionsController
{
    public function index(Request $request): Response
    {
        $profiles = $request->user()
            ->trackerProfiles()
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => $p->toCliArray() + ['id' => $p->id]);

        return Inertia::render('Console/Connections', [
            'profiles' => $profiles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/',
                                   Rule::unique('tracker_profiles')->where('user_id', $request->user()->id)],
            'tracker_type'     => ['required', Rule::in(['jira', 'github'])],
            'base_url'         => ['required', 'string', 'url', 'max:500'],
            'auth_method'      => ['required', 'string', Rule::in(['cloud', 'pat', 'basic', 'github'])],
            'email'            => ['nullable', 'string', 'max:255'],
            'ticket_prefixes'  => ['nullable', 'array'],
            'ticket_prefixes.*'=> ['string', 'max:20'],
            'project_paths'    => ['nullable', 'array'],
            'project_paths.*'  => ['string', 'max:500'],
            'triage_statuses'  => ['nullable', 'array'],
            'triage_statuses.*'=> ['string', 'max:100'],
        ]);

        $request->user()->trackerProfiles()->create($data);

        return redirect()->route('console.connections')->with('success', 'Connection added.');
    }

    public function update(Request $request, TrackerProfile $trackerProfile): RedirectResponse
    {
        $this->authorise($request, $trackerProfile);

        $data = $request->validate([
            'name'             => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/',
                                   Rule::unique('tracker_profiles')->where('user_id', $request->user()->id)->ignore($trackerProfile->id)],
            'tracker_type'     => ['required', Rule::in(['jira', 'github'])],
            'base_url'         => ['required', 'string', 'url', 'max:500'],
            'auth_method'      => ['required', 'string', Rule::in(['cloud', 'pat', 'basic', 'github'])],
            'email'            => ['nullable', 'string', 'max:255'],
            'ticket_prefixes'  => ['nullable', 'array'],
            'ticket_prefixes.*'=> ['string', 'max:20'],
            'project_paths'    => ['nullable', 'array'],
            'project_paths.*'  => ['string', 'max:500'],
            'triage_statuses'  => ['nullable', 'array'],
            'triage_statuses.*'=> ['string', 'max:100'],
        ]);

        $trackerProfile->update($data);

        return redirect()->route('console.connections')->with('success', 'Connection updated.');
    }

    public function destroy(Request $request, TrackerProfile $trackerProfile): RedirectResponse
    {
        $this->authorise($request, $trackerProfile);
        $trackerProfile->delete();

        return redirect()->route('console.connections')->with('success', 'Connection removed.');
    }

    private function authorise(Request $request, TrackerProfile $profile): void
    {
        abort_unless($profile->user_id === $request->user()->id, 403);
    }
}
