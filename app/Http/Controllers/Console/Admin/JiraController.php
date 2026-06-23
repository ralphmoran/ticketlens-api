<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamJiraConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class JiraController extends Controller
{
    public function index(Request $request): Response
    {
        $user   = $request->user();
        $group  = $user->ownedGroup ?? $user->groups()->first();
        $config = $group ? TeamJiraConfig::where('group_id', $group->id)->first() : null;

        return Inertia::render('Console/Admin/Jira', [
            'group'  => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'config' => $config ? [
                'jira_base_url'   => $config->jira_base_url,
                'auth_type'       => $config->auth_type,
                'prefixes'        => $config->prefixes,
                'project_paths'   => $config->project_paths,
                'triage_statuses' => $config->triage_statuses,
                'updated_at'      => $config->updated_at?->toISOString(),
            ] : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'jira_base_url'     => ['required', 'url', 'regex:/^https:\/\//i', 'max:255'],
            'auth_type'         => ['required', 'in:cloud,server,pat'],
            'prefixes'          => ['nullable', 'array'],
            'prefixes.*'        => ['string', 'max:20'],
            'project_paths'     => ['nullable', 'array'],
            'project_paths.*'   => ['string', 'max:500'],
            'triage_statuses'   => ['nullable', 'array'],
            'triage_statuses.*' => ['string', 'max:100'],
        ]);

        $this->assertSafeUrl($validated['jira_base_url']);

        $group = $request->user()->ownedGroup;
        abort_unless($group !== null, 403, 'No team found.');

        TeamJiraConfig::updateOrCreate(['group_id' => $group->id], $validated);

        return back()->with('success', 'Jira configuration saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $group = $request->user()->ownedGroup;
        abort_unless($group !== null, 403, 'No team found.');

        TeamJiraConfig::where('group_id', $group->id)->delete();

        return back()->with('success', 'Jira configuration removed.');
    }

    private function assertSafeUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            throw ValidationException::withMessages(['jira_base_url' => 'Invalid Jira URL.']);
        }

        // Strip IPv6 brackets before IP validation
        $bare = ltrim(rtrim($host, ']'), '[');

        if (in_array(strtolower($bare), ['localhost', '127.0.0.1', '::1'], true)) {
            throw ValidationException::withMessages(['jira_base_url' => 'Private or loopback hosts are not allowed.']);
        }

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ValidationException::withMessages(['jira_base_url' => 'Private or reserved IP addresses are not allowed.']);
            }
        }
    }
}
