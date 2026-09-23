<?php

namespace Tests\Feature\Owner;

use App\Models\ErrorReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    private function makeReport(array $overrides = []): ErrorReport
    {
        return ErrorReport::create(array_merge([
            'cli_version'  => '0.39.5',
            'os'           => 'darwin',
            'command'      => 'note add',
            'message'      => 'ENOENT: no such file or directory',
            'profile_tier' => 'pro',
        ], $overrides));
    }

    public function test_owner_can_view_error_reports(): void
    {
        $owner = $this->makeOwner();
        $this->makeReport();

        $response = $this->actingAs($owner)->get('/console/owner/error-reports');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/ErrorReports/Index')
            ->has('reports.data', 1)
        );
    }

    public function test_non_owner_cannot_view_error_reports(): void
    {
        $user = User::factory()->create(['permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/error-reports');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_error_reports_are_paginated(): void
    {
        $owner = $this->makeOwner();
        for ($i = 0; $i < 30; $i++) {
            $this->makeReport();
        }

        $response = $this->actingAs($owner)->get('/console/owner/error-reports');

        $response->assertInertia(fn ($page) => $page->has('reports.data', 10));
    }

    public function test_error_reports_caps_per_page_at_100(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->get('/console/owner/error-reports?per_page=9999');

        $response->assertInertia(fn ($page) => $page->where('filters.per_page', 100));
    }

    public function test_error_reports_filterable_by_cli_version(): void
    {
        $owner = $this->makeOwner();
        $this->makeReport(['cli_version' => '0.39.5']);
        $this->makeReport(['cli_version' => '0.38.0']);

        $response = $this->actingAs($owner)->get('/console/owner/error-reports?cli_version=0.39.5');

        $response->assertInertia(fn ($page) => $page->has('reports.data', 1));
    }

    public function test_error_reports_filterable_by_profile_tier(): void
    {
        $owner = $this->makeOwner();
        $this->makeReport(['profile_tier' => 'pro']);
        $this->makeReport(['profile_tier' => 'free']);

        $response = $this->actingAs($owner)->get('/console/owner/error-reports?profile_tier=free');

        $response->assertInertia(fn ($page) => $page->has('reports.data', 1));
    }

    public function test_error_reports_filterable_by_message_search(): void
    {
        $owner = $this->makeOwner();
        $this->makeReport(['message' => 'ENOENT: file missing']);
        $this->makeReport(['message' => 'Network timeout']);

        $response = $this->actingAs($owner)->get('/console/owner/error-reports?search=ENOENT');

        $response->assertInertia(fn ($page) => $page->has('reports.data', 1));
    }

    public function test_error_reports_filterable_by_command_search(): void
    {
        $owner = $this->makeOwner();
        $this->makeReport(['command' => 'note add']);
        $this->makeReport(['command' => 'ticket create']);

        $response = $this->actingAs($owner)->get('/console/owner/error-reports?search=ticket');

        $response->assertInertia(fn ($page) => $page->has('reports.data', 1));
    }

    public function test_error_reports_with_no_matches_returns_empty(): void
    {
        $owner = $this->makeOwner();
        $this->makeReport();

        $response = $this->actingAs($owner)->get('/console/owner/error-reports?search=nonexistent-term');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('reports.data', 0));
    }
}
