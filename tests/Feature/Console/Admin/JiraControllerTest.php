<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Group;
use App\Models\License;
use App\Models\TeamJiraConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JiraControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        License::create([
            'user_id'        => $manager->id,
            'lemon_key_hash' => hash('sha256', "manager-{$manager->id}"),
            'status'         => 'active',
            'tier'           => 'team',
            'seats'          => 5,
        ]);
        return $manager;
    }

    // ── Auth guard ───────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/admin/jira')->assertRedirect('/console/login');
    }

    // ── Permission gate — lock invariant #2 ──────────────────────────────────

    public function test_plain_team_member_without_manager_permissions_is_redirected(): void
    {
        $user = User::factory()->create(['tier' => 'team', 'permissions' => 127]);
        $this->actingAs($user)->get('/console/admin/jira')->assertRedirect('/console/dashboard');
    }

    public function test_free_user_is_redirected_from_jira_config_page(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 0]);
        $this->actingAs($user)->get('/console/admin/jira')->assertRedirect('/console/dashboard');
    }

    // ── Input validation — lock invariant #5 ────────────────────────────────

    public function test_invalid_url_scheme_is_rejected(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)
            ->put('/console/admin/jira', [
                'jira_base_url' => 'javascript:alert(1)',
                'auth_type'     => 'cloud',
            ])
            ->assertSessionHasErrors(['jira_base_url']);
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)
            ->put('/console/admin/jira', [
                'jira_base_url' => 'ftp://acme.example.com',
                'auth_type'     => 'cloud',
            ])
            ->assertSessionHasErrors(['jira_base_url']);
    }

    public function test_manager_can_access_jira_config_page(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)
            ->get('/console/admin/jira')
            ->assertStatus(200);
    }

    public function test_http_url_is_rejected(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)
            ->put('/console/admin/jira', [
                'jira_base_url' => 'http://acme.atlassian.net',
                'auth_type'     => 'cloud',
            ])
            ->assertSessionHasErrors(['jira_base_url']);
    }

    public function test_private_ip_is_rejected(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)
            ->put('/console/admin/jira', [
                'jira_base_url' => 'https://192.168.1.1',
                'auth_type'     => 'cloud',
            ])
            ->assertSessionHasErrors(['jira_base_url']);
    }

    public function test_loopback_host_is_rejected(): void
    {
        $manager = $this->makeManager();
        $this->actingAs($manager)
            ->put('/console/admin/jira', [
                'jira_base_url' => 'https://localhost/jira',
                'auth_type'     => 'cloud',
            ])
            ->assertSessionHasErrors(['jira_base_url']);
    }

    public function test_manager_can_save_jira_configuration(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)
            ->put('/console/admin/jira', [
                'jira_base_url' => 'https://acme.atlassian.net',
                'auth_type'     => 'cloud',
                'prefixes'      => ['PROJ', 'OPS'],
                'project_paths' => ['/code/acme'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('team_jira_configs', [
            'jira_base_url' => 'https://acme.atlassian.net',
            'auth_type'     => 'cloud',
        ]);
    }

    public function test_manager_can_remove_jira_configuration(): void
    {
        $manager = $this->makeManager();
        $group   = $manager->ownedGroup;

        TeamJiraConfig::create([
            'group_id'      => $group->id,
            'jira_base_url' => 'https://acme.atlassian.net',
            'auth_type'     => 'cloud',
        ]);

        $this->actingAs($manager)
            ->delete('/console/admin/jira')
            ->assertRedirect();

        $this->assertDatabaseMissing('team_jira_configs', ['group_id' => $group->id]);
    }
}
