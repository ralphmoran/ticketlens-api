<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Models\BriefTemplate;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lock tests for Security Audit Phase 3.
 * Characterise EXISTING behaviour that must be preserved through all Phase 3 fixes.
 */
class Phase3LockTest extends TestCase
{
    use RefreshDatabase;

    // ── BriefTemplate auth — Phase 1 invariants ─────────────────────────────

    public function test_cross_group_user_cannot_update_another_groups_template(): void
    {
        $owner = $this->makeManager();
        $group = $owner->groups()->first();

        $template = BriefTemplate::create([
            'group_id'   => $group->id,
            'slug'       => 'team-template',
            'name'       => 'Team Template',
            'sections'   => $this->sections(),
            'is_system'  => false,
            'created_by' => $owner->id,
        ]);

        $otherUser = $this->makeManager();

        $this->actingAs($otherUser)
            ->put("/console/admin/templates/{$template->id}", [
                'name'     => 'Hijacked',
                'sections' => $this->sections(),
            ])
            ->assertStatus(403);
    }

    public function test_cross_group_user_cannot_delete_another_groups_template(): void
    {
        $owner = $this->makeManager();
        $group = $owner->groups()->first();

        $template = BriefTemplate::create([
            'group_id'   => $group->id,
            'slug'       => 'del-template',
            'name'       => 'Delete Target',
            'sections'   => $this->sections(),
            'is_system'  => false,
            'created_by' => $owner->id,
        ]);

        $otherUser = $this->makeManager();

        $this->actingAs($otherUser)
            ->delete("/console/admin/templates/{$template->id}")
            ->assertStatus(403);
    }

    public function test_system_template_cannot_be_updated(): void
    {
        $manager  = $this->makeManager();
        $template = BriefTemplate::create([
            'group_id'   => null,
            'slug'       => 'sys-template',
            'name'       => 'System Template',
            'sections'   => $this->sections(),
            'is_system'  => true,
            'created_by' => null,
        ]);

        $this->actingAs($manager)
            ->put("/console/admin/templates/{$template->id}", [
                'name'     => 'Mutated',
                'sections' => $this->sections(),
            ])
            ->assertStatus(403);
    }

    public function test_system_template_cannot_be_deleted(): void
    {
        $manager  = $this->makeManager();
        $template = BriefTemplate::create([
            'group_id'   => null,
            'slug'       => 'sys-del-template',
            'name'       => 'System Delete Target',
            'sections'   => $this->sections(),
            'is_system'  => true,
            'created_by' => null,
        ]);

        $this->actingAs($manager)
            ->delete("/console/admin/templates/{$template->id}")
            ->assertStatus(403);
    }

    // ── Login — existing validation behaviour ────────────────────────────────

    public function test_login_bad_credentials_returns_validation_error(): void
    {
        $this->post('/console/login', [
            'email'    => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertSessionHasErrors('email');
    }

    public function test_login_missing_fields_returns_validation_error(): void
    {
        $this->post('/console/login', [])->assertSessionHasErrors(['email', 'password']);
    }

    // ── Security headers — always-set invariants ─────────────────────────────

    public function test_x_content_type_options_always_set(): void
    {
        $this->get('/console/login')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_x_frame_options_always_set(): void
    {
        $this->get('/console/login')->assertHeader('X-Frame-Options', 'DENY');
    }

    // ── /s/{token} — public access still works ──────────────────────────────

    public function test_share_page_with_unknown_token_returns_404(): void
    {
        $this->get('/s/unknown-token-xyz')->assertStatus(404);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeManager(): User
    {
        $manager = User::factory()->create([
            'tier'        => 'team',
            'permissions' => Permission::team() | Permission::teamManagerMask(),
        ]);
        $group = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        License::create([
            'user_id'        => $manager->id,
            'lemon_key_hash' => hash('sha256', 'mgr-' . $manager->id),
            'status'         => 'active',
            'tier'           => 'team',
            'seats'          => 5,
        ]);
        return $manager;
    }

    private function sections(): array
    {
        return [
            'meta'        => true,
            'description' => true,
            'comments'    => ['enabled' => true, 'max' => 5],
            'linked'      => false,
            'code_refs'   => false,
            'confluence'  => false,
            'attachments' => false,
        ];
    }
}
