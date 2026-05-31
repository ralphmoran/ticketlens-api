<?php

namespace Tests\Feature\Console\Admin;

use App\Models\BriefTemplate;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\BriefTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BriefTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $tier, bool $isOwner = false): User
    {
        return User::factory()->create([
            'tier'        => $tier,
            'permissions' => 0,
            'is_owner'    => $isOwner,
        ]);
    }

    private function makeTeamManager(): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 639]);
        $group   = Group::create(['name' => 'Test Team', 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        return $manager;
    }

    private function makeSections(): array
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

    private function seedSystemTemplates(): void
    {
        (new BriefTemplateSeeder())->run();
    }

    // ── Index ──────────────────────────────────────────────────────────────

    public function test_index_returns_templates_page_for_pro_user(): void
    {
        $this->seedSystemTemplates();
        $user = $this->makeUser('pro');

        $this->actingAs($user)->get('/console/admin/templates')
            ->assertStatus(200);
    }

    public function test_index_accessible_for_team_manager(): void
    {
        $this->seedSystemTemplates();
        $manager = $this->makeTeamManager();

        $this->actingAs($manager)->get('/console/admin/templates')
            ->assertStatus(200);
    }

    // ── Store ──────────────────────────────────────────────────────────────

    public function test_pro_user_without_group_cannot_create_custom_template(): void
    {
        // Pro users have no Team group — custom templates require Team membership
        $user = $this->makeUser('pro');

        $this->actingAs($user)->post('/console/admin/templates', [
            'name'     => 'Pro Template',
            'sections' => $this->makeSections(),
        ])->assertStatus(403);
    }

    public function test_free_user_cannot_create_custom_template(): void
    {
        $user = $this->makeUser('free');

        $this->actingAs($user)->post('/console/admin/templates', [
            'name'     => 'Free Template',
            'sections' => $this->makeSections(),
        ])->assertStatus(403);
    }

    public function test_team_manager_can_create_custom_template(): void
    {
        $manager = $this->makeTeamManager();

        $response = $this->actingAs($manager)->post('/console/admin/templates', [
            'name'     => 'Team Template',
            'sections' => $this->makeSections(),
        ]);

        $response->assertRedirect('/console/admin/templates');
        $this->assertDatabaseHas('brief_templates', ['name' => 'Team Template', 'is_system' => false]);
    }

    // ── Update ──────────────────────────────────────────────────────────────

    public function test_user_can_update_own_custom_template(): void
    {
        $manager = $this->makeTeamManager();
        $group   = $manager->groups()->first();

        $template = BriefTemplate::create([
            'group_id'  => $group->id,
            'slug'      => 'my-template',
            'name'      => 'Old Name',
            'sections'  => $this->makeSections(),
            'is_system' => false,
            'created_by' => $manager->id,
        ]);

        $this->actingAs($manager)->put("/console/admin/templates/{$template->id}", [
            'name'     => 'New Name',
            'sections' => $this->makeSections(),
        ])->assertRedirect('/console/admin/templates');

        $this->assertDatabaseHas('brief_templates', ['id' => $template->id, 'name' => 'New Name']);
    }

    public function test_system_template_cannot_be_updated(): void
    {
        $this->seedSystemTemplates();
        $manager = $this->makeTeamManager();
        $system  = BriefTemplate::where('is_system', true)->first();

        $this->actingAs($manager)->put("/console/admin/templates/{$system->id}", [
            'name'     => 'Hijacked',
            'sections' => $this->makeSections(),
        ])->assertStatus(403);
    }

    // ── Destroy ──────────────────────────────────────────────────────────────

    public function test_user_can_delete_own_custom_template(): void
    {
        $manager = $this->makeTeamManager();
        $group   = $manager->groups()->first();

        $template = BriefTemplate::create([
            'group_id'  => $group->id,
            'slug'      => 'to-delete',
            'name'      => 'To Delete',
            'sections'  => $this->makeSections(),
            'is_system' => false,
        ]);

        $this->actingAs($manager)->delete("/console/admin/templates/{$template->id}")
            ->assertRedirect('/console/admin/templates');

        $this->assertDatabaseMissing('brief_templates', ['id' => $template->id]);
    }

    public function test_system_template_cannot_be_deleted(): void
    {
        $this->seedSystemTemplates();
        $manager = $this->makeTeamManager();
        $system  = BriefTemplate::where('is_system', true)->first();

        $this->actingAs($manager)->delete("/console/admin/templates/{$system->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('brief_templates', ['id' => $system->id]);
    }
}
