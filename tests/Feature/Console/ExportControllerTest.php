<?php

namespace Tests\Feature\Console;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportControllerTest extends TestCase
{
    use RefreshDatabase;

    // LOCK: Export page renders Console/Export Inertia component for authorised user
    public function test_export_page_renders_for_team_user(): void
    {
        $user = User::factory()->create([
            'tier'        => 'team',
            'permissions' => Permission::team(),
        ]);

        $this->actingAs($user)
            ->get('/console/export')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Console/Export'));
    }

    // LOCK: guests are redirected to login, not served the page
    public function test_export_page_redirects_guest_to_login(): void
    {
        $this->get('/console/export')->assertRedirect('/console/login');
    }

    // LOCK: users without Export permission are redirected to the upgrade page
    public function test_export_page_redirects_user_without_permission(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 0]);

        $this->actingAs($user)
            ->get('/console/export')
            ->assertRedirect('/console/upgrade');
    }
}
