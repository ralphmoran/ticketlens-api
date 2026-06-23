<?php

namespace App\Http\Controllers\Console\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamJiraConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jira_base_url' => ['required', 'url', 'regex:/^https:\/\//i', 'max:255'],
            'auth_type'     => ['required', 'in:cloud,server,pat'],
            'email'         => ['required_if:auth_type,cloud', 'nullable', 'email', 'max:255'],
            'api_token'     => ['required_if:auth_type,cloud', 'nullable', 'string', 'max:500'],
            'username'      => ['required_if:auth_type,server', 'nullable', 'string', 'max:255'],
            'password'      => ['required_if:auth_type,server', 'nullable', 'string', 'max:500'],
            'pat'           => ['required_if:auth_type,pat', 'nullable', 'string', 'max:500'],
        ]);

        $this->assertSafeUrl($validated['jira_base_url']);

        $authHeader = match ($validated['auth_type']) {
            'cloud'  => 'Basic ' . base64_encode($validated['email'] . ':' . $validated['api_token']),
            'server' => 'Basic ' . base64_encode($validated['username'] . ':' . $validated['password']),
            'pat'    => 'Bearer ' . $validated['pat'],
        };

        $base    = rtrim($validated['jira_base_url'], '/');
        $isCloud = str_ends_with(parse_url($base, PHP_URL_HOST), '.atlassian.net');
        $apiVer  = $isCloud ? '3' : '2';

        $rawProjects = $this->jiraGet("{$base}/rest/api/{$apiVer}/project?maxResults=200", $authHeader);
        $rawStatuses = $this->jiraGet("{$base}/rest/api/{$apiVer}/status", $authHeader);

        $projects = isset($rawProjects['values']) ? $rawProjects['values'] : $rawProjects;

        return response()->json([
            'projects' => array_map(fn ($p) => ['key' => $p['key'], 'name' => $p['name']], $projects),
            'statuses' => array_column($rawStatuses, 'name'),
        ]);
    }

    private function jiraGet(string $url, string $authHeader): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeader('Authorization', $authHeader)
                ->withHeader('Accept', 'application/json')
                ->get($url);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['jira_base_url' => 'Could not reach Jira: ' . $e->getMessage()]);
        }

        if ($response->status() === 401) {
            throw ValidationException::withMessages(['credentials' => 'Invalid credentials — check your email and API token.']);
        }
        if ($response->status() === 403) {
            throw ValidationException::withMessages(['credentials' => 'Access denied — the credentials lack permission to list projects.']);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['jira_base_url' => "Jira returned HTTP {$response->status()}."]);
        }

        return $response->json() ?? [];
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
