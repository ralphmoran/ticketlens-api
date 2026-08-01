<?php

namespace Tests\Feature\Console\Admin;

use App\Models\Feature;
use App\Models\Group;
use App\Models\RecallNote;
use App\Models\User;
use App\Models\UserFeatureGrant;
use App\Services\SseEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecallControllerTest extends TestCase
{
    use RefreshDatabase;

    private function grantRecall(User $user, User $grantedBy): void
    {
        $feature = Feature::firstOrCreate(['name' => 'recall'], ['bit_value' => 4096, 'label' => 'Recall', 'sort_order' => 100]);
        UserFeatureGrant::create(['user_id' => $user->id, 'feature_id' => $feature->id, 'granted_by' => $grantedBy->id]);
    }

    // team(2687) | teamManagerMask(384) = 3071 — must include TeamManageMembers(128) for EnsureTeamManager
    private function makeManager(): array
    {
        $owner   = User::factory()->create(['is_owner' => true]);
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 3071]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        $this->grantRecall($manager, $owner);
        return [$manager, $group, $owner];
    }

    private function makeEntitledMember(Group $group, User $grantedBy): User
    {
        $member = User::factory()->create(['tier' => 'pro']);
        $group->members()->attach($member->id);
        $this->grantRecall($member, $grantedBy);
        return $member;
    }

    // ---- index: auth + entitlement ----

    public function test_index_requires_auth(): void
    {
        $this->get('/console/admin/recall')->assertRedirect('/console/login');
    }

    public function test_index_blocks_a_non_entitled_user(): void
    {
        $user  = User::factory()->create(['tier' => 'free', 'permissions' => 64]);
        $group = Group::create(['name' => 'G', 'owner_id' => $user->id]);
        $group->members()->attach($user->id);

        $this->actingAs($user)->get('/console/admin/recall')->assertRedirect('/console/upgrade');
    }

    public function test_index_is_accessible_to_any_entitled_member_not_manager_only(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $member = $this->makeEntitledMember($group, $owner);

        $this->actingAs($member)->get('/console/admin/recall')->assertOk();
    }

    public function test_index_only_returns_notes_belonging_to_the_callers_own_group(): void
    {
        [$manager, $groupA, $owner] = $this->makeManager();
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);

        RecallNote::create([
            'group_id' => $groupA->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'Group A note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => $owner->id, 'external_id' => 'b.md',
            'title' => 'Group B note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('notes.data', 1)
                ->where('notes.data.0.title', 'Group A note'));
    }

    public function test_index_defaults_to_ten_per_page(): void
    {
        [$manager, $group] = $this->makeManager();
        for ($i = 0; $i < 11; $i++) {
            RecallNote::create([
                'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => "n{$i}.md",
                'title' => "Note {$i}", 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
            ]);
        }

        $this->actingAs($manager)->get('/console/admin/recall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 10)->where('notes.total', 11));
    }

    public function test_index_search_matches_title(): void
    {
        [$manager, $group] = $this->makeManager();
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'Retry gotcha', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'Unrelated note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall?search=gotcha')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 1)->where('notes.data.0.title', 'Retry gotcha'));
    }

    public function test_index_search_matches_body(): void
    {
        [$manager, $group] = $this->makeManager();
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'Needs exponential backoff.',
        ]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'y', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'Something else entirely.',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall?search=backoff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 1)->where('notes.data.0.body', 'Needs exponential backoff.'));
    }

    public function test_index_search_matches_tags(): void
    {
        [$manager, $group] = $this->makeManager();
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => ['routeone', 'schema-scope'], 'sources' => [], 'body' => 'y',
        ]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => ['unrelated'], 'sources' => [], 'body' => 'y',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall?search=schema-scope')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 1)->where('notes.data.0.tags', ['routeone', 'schema-scope']));
    }

    public function test_index_search_never_crosses_group_boundaries(): void
    {
        [$managerA, $groupA] = $this->makeManager();
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);
        RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => User::factory()->create()->id, 'external_id' => 'b.md',
            'title' => 'Shared keyword', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($managerA)->get('/console/admin/recall?search=Shared')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 0));
    }

    public function test_index_returns_the_authors_name_tier_and_avatar_for_the_drawer(): void
    {
        [$manager, $group] = $this->makeManager();
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notes.data.0.author.name', $manager->name)
                ->where('notes.data.0.author.tier', 'team')
                ->where('notes.data.0.author.avatar_url', null));
    }

    public function test_index_includes_the_note_body_so_a_verifier_can_read_it_before_deciding(): void
    {
        [$manager, $group] = $this->makeManager();
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'Needs exponential backoff.',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('notes.data.0.body', 'Needs exponential backoff.'));
    }

    // ---- index: filters (status, author, tag — combinable) ----

    public function test_index_filters_by_status(): void
    {
        [$manager, $group] = $this->makeManager();
        $verified = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'Verified note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
            'status' => 'verified',
        ]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'Unverified note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall?status=verified')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 1)->where('notes.data.0.id', $verified->id));
    }

    public function test_index_filters_by_author(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $other = $this->makeEntitledMember($group, $owner);
        $mine  = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'Mine', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $other->id, 'external_id' => 'b.md',
            'title' => 'Theirs', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->get("/console/admin/recall?author_id={$manager->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 1)->where('notes.data.0.id', $mine->id));
    }

    public function test_index_filters_by_tag_as_an_exact_match_not_a_substring(): void
    {
        // Lock: "test" must not match a note tagged only "testing" — this is
        // whereJsonContains, a different code path from the fuzzy `search`
        // param's LIKE-based tag matching (see test_index_search_matches_tags).
        [$manager, $group] = $this->makeManager();
        $exact = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => ['test'], 'sources' => [], 'body' => 'x',
        ]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => ['testing'], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall?tag=test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 1)->where('notes.data.0.id', $exact->id));
    }

    public function test_index_combines_status_author_and_tag_filters(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $other  = $this->makeEntitledMember($group, $owner);
        $target = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'Target', 'aliases' => [], 'tickets' => [], 'tags' => ['routeone'], 'sources' => [], 'body' => 'x',
            'status' => 'verified',
        ]);
        // Same author + tag, wrong status
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'Wrong status', 'aliases' => [], 'tickets' => [], 'tags' => ['routeone'], 'sources' => [], 'body' => 'x',
        ]);
        // Same status + tag, wrong author
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $other->id, 'external_id' => 'c.md',
            'title' => 'Wrong author', 'aliases' => [], 'tickets' => [], 'tags' => ['routeone'], 'sources' => [], 'body' => 'x',
            'status' => 'verified',
        ]);
        // Same status + author, wrong tag
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'd.md',
            'title' => 'Wrong tag', 'aliases' => [], 'tickets' => [], 'tags' => ['other'], 'sources' => [], 'body' => 'x',
            'status' => 'verified',
        ]);

        $this->actingAs($manager)
            ->get("/console/admin/recall?status=verified&author_id={$manager->id}&tag=routeone")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes.data', 1)->where('notes.data.0.id', $target->id));
    }

    public function test_index_returns_distinct_author_and_tag_options_scoped_to_the_group(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $other = $this->makeEntitledMember($group, $owner);
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);

        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => ['routeone', 'gotcha'], 'sources' => [], 'body' => 'x',
        ]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => ['gotcha'], 'sources' => [], 'body' => 'x',
        ]);
        RecallNote::create([
            'group_id' => $group->id, 'author_id' => $other->id, 'external_id' => 'c.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        // Different group — must never leak into these options.
        RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => User::factory()->create()->id, 'external_id' => 'd.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => ['other-groups-tag'], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->get('/console/admin/recall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('authorOptions', 2)
                ->where('tagOptions', ['gotcha', 'routeone']));
    }

    public function test_index_returns_empty_options_for_an_owner_with_no_active_group(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->actingAs($owner)->get('/console/admin/recall')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('group', null)
                ->where('notes', null)
                ->where('authorOptions', [])
                ->where('tagOptions', []));
    }

    // ---- verify: manager-only (IDOR-safe) ----

    public function test_verify_blocks_a_non_manager_even_if_recall_entitled(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $member = $this->makeEntitledMember($group, $owner);
        $note   = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($member)->post("/console/admin/recall/{$note->id}/verify")
            ->assertRedirect('/console/dashboard');
        $this->assertSame('unverified', $note->fresh()->status);
    }

    public function test_verify_blocks_a_manager_without_the_recall_grant(): void
    {
        // Recall entitlement is per-user (feature-grant/tier), not implied by
        // team.manager. A manager can legitimately lack it while other team
        // members have it — verify() must check Permission::Recall itself,
        // not rely solely on the team.manager route group.
        $owner   = User::factory()->create(['is_owner' => true]);
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 3071]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->post("/console/admin/recall/{$note->id}/verify")->assertRedirect('/console/upgrade');
        $this->assertSame('unverified', $note->fresh()->status);
    }

    public function test_manager_can_verify_a_note_in_their_own_group(): void
    {
        [$manager, $group] = $this->makeManager();
        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->post("/console/admin/recall/{$note->id}/verify")->assertRedirect();

        $fresh = $note->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertSame($manager->id, $fresh->verified_by);
        $this->assertNotNull($fresh->verified_at);
    }

    public function test_verify_publishes_notification_updated_event(): void
    {
        [$manager, $group] = $this->makeManager();
        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->mock(SseEventService::class)
            ->shouldReceive('publish')
            ->once()
            ->with($group->id, 'notification.updated', []);

        $this->actingAs($manager)->post("/console/admin/recall/{$note->id}/verify")->assertRedirect();
    }

    public function test_verify_writes_no_audit_log(): void
    {
        // Lock: unlike destroy() and updateSettings(), verify() has never called
        // AuditService — bulkVerify() must preserve this asymmetry rather than
        // "fixing" it as a side effect of adding bulk actions.
        [$manager, $group] = $this->makeManager();
        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->post("/console/admin/recall/{$note->id}/verify")->assertRedirect();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_owner_can_verify_a_note_in_a_group_they_do_not_personally_own(): void
    {
        // Regression: verify() must resolve group the same way index() does (via
        // ?group_id= for an owner), not assume $user->ownedGroup — an owner viewing
        // a client's team has no ownedGroup of their own.
        [$manager, $group, $owner] = $this->makeManager();
        $note  = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($owner)->post("/console/admin/recall/{$note->id}/verify?group_id={$group->id}")->assertRedirect();

        $fresh = $note->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertSame($owner->id, $fresh->verified_by);
    }

    public function test_owner_without_a_group_id_param_gets_403_not_a_silent_no_op(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $note  = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($owner)->post("/console/admin/recall/{$note->id}/verify")->assertStatus(403);
        $this->assertSame('unverified', $note->fresh()->status);
    }

    public function test_a_manager_cannot_verify_a_note_belonging_to_a_different_group_idor(): void
    {
        [$managerA, $groupA] = $this->makeManager();
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);
        $note = RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => User::factory()->create()->id, 'external_id' => 'b.md',
            'title' => 'Group B note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($managerA)->post("/console/admin/recall/{$note->id}/verify")->assertStatus(403);
        $this->assertSame('unverified', $note->fresh()->status);
    }

    // ---- destroy: manager-only (mirrors verify's authorization exactly) ----

    public function test_manager_can_delete_a_note_in_their_own_group(): void
    {
        [$manager, $group] = $this->makeManager();
        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->delete("/console/admin/recall/{$note->id}")->assertRedirect();

        $this->assertNull(RecallNote::find($note->id));
        $this->assertNotNull(RecallNote::withTrashed()->find($note->id)->deleted_at);
    }

    public function test_destroy_publishes_notification_updated_event(): void
    {
        [$manager, $group] = $this->makeManager();
        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->mock(SseEventService::class)
            ->shouldReceive('publish')
            ->once()
            ->with($group->id, 'notification.updated', []);

        $this->actingAs($manager)->delete("/console/admin/recall/{$note->id}")->assertRedirect();
    }

    public function test_destroy_blocks_a_non_manager_even_if_recall_entitled(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $member = $this->makeEntitledMember($group, $owner);
        $note   = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($member)->delete("/console/admin/recall/{$note->id}")
            ->assertRedirect('/console/dashboard');
        $this->assertNotNull(RecallNote::find($note->id));
    }

    public function test_owner_can_delete_a_note_in_a_group_they_do_not_personally_own(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $note  = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($owner)->delete("/console/admin/recall/{$note->id}?group_id={$group->id}")->assertRedirect();

        $this->assertNull(RecallNote::find($note->id));
    }

    public function test_owner_without_a_group_id_param_gets_403_not_a_silent_delete(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $note  = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($owner)->delete("/console/admin/recall/{$note->id}")->assertStatus(403);
        $this->assertNotNull(RecallNote::find($note->id));
    }

    public function test_a_manager_cannot_delete_a_note_belonging_to_a_different_group_idor(): void
    {
        [$managerA, $groupA] = $this->makeManager();
        $groupB = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);
        $note = RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => User::factory()->create()->id, 'external_id' => 'b.md',
            'title' => 'Group B note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($managerA)->delete("/console/admin/recall/{$note->id}")->assertStatus(403);
        $this->assertNotNull(RecallNote::find($note->id));
    }

    public function test_destroy_writes_an_audit_log_with_the_deleted_notes_identity(): void
    {
        [$manager, $group] = $this->makeManager();
        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'Retry gotcha', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->delete("/console/admin/recall/{$note->id}");

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $manager->id,
            'action'   => 'recall.deleted',
        ]);
        $log = \App\Models\AuditLog::where('action', 'recall.deleted')->first();
        $this->assertSame('Retry gotcha', $log->old_value['title']);
        $this->assertSame('a.md', $log->old_value['external_id']);
        $this->assertSame($group->id, $log->old_value['group_id']);
        $this->assertSame($note->id, $log->metadata['note_id']);
    }

    // ---- bulkVerify: batches verify() across a page-scoped selection ----

    public function test_bulk_verify_marks_every_selected_note_verified(): void
    {
        [$manager, $group] = $this->makeManager();
        $noteA = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        $noteB = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->post('/console/admin/recall/bulk-verify', ['ids' => [$noteA->id, $noteB->id]])
            ->assertRedirect();

        $this->assertSame('verified', $noteA->fresh()->status);
        $this->assertSame('verified', $noteB->fresh()->status);
    }

    public function test_bulk_verify_ignores_note_ids_belonging_to_a_different_group_idor(): void
    {
        [$managerA, $groupA] = $this->makeManager();
        $groupB   = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);
        $foreign  = RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => User::factory()->create()->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($managerA)->post('/console/admin/recall/bulk-verify', ['ids' => [$foreign->id]])
            ->assertRedirect();

        $this->assertSame('unverified', $foreign->fresh()->status);
    }

    public function test_bulk_verify_processes_the_valid_id_when_batch_mixes_own_and_foreign_group_ids(): void
    {
        [$managerA, $groupA] = $this->makeManager();
        $groupB  = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);
        $ownNote = RecallNote::create([
            'group_id' => $groupA->id, 'author_id' => $managerA->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        $foreign = RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => User::factory()->create()->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($managerA)->post('/console/admin/recall/bulk-verify', ['ids' => [$ownNote->id, $foreign->id]])
            ->assertRedirect();

        $this->assertSame('verified', $ownNote->fresh()->status);
        $this->assertSame('unverified', $foreign->fresh()->status);
    }

    public function test_bulk_verify_rejects_an_empty_ids_array(): void
    {
        [$manager] = $this->makeManager();

        $this->actingAs($manager)->post('/console/admin/recall/bulk-verify', ['ids' => []])
            ->assertSessionHasErrors('ids');
    }

    public function test_bulk_verify_blocks_a_non_manager_even_if_recall_entitled(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $member = $this->makeEntitledMember($group, $owner);
        $note   = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($member)->post('/console/admin/recall/bulk-verify', ['ids' => [$note->id]])
            ->assertRedirect('/console/dashboard');
        $this->assertSame('unverified', $note->fresh()->status);
    }

    public function test_bulk_verify_writes_no_audit_log(): void
    {
        // Mirrors test_verify_writes_no_audit_log — batching must not introduce
        // auditing that the single-note action never had.
        [$manager, $group] = $this->makeManager();
        $note = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->post('/console/admin/recall/bulk-verify', ['ids' => [$note->id]]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    // ---- bulkDestroy: batches destroy() across a page-scoped selection ----

    public function test_bulk_destroy_deletes_every_selected_note(): void
    {
        [$manager, $group] = $this->makeManager();
        $noteA = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        $noteB = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->delete('/console/admin/recall/bulk', ['ids' => [$noteA->id, $noteB->id]])
            ->assertRedirect();

        $this->assertNull(RecallNote::find($noteA->id));
        $this->assertNull(RecallNote::find($noteB->id));
    }

    public function test_bulk_destroy_ignores_note_ids_belonging_to_a_different_group_idor(): void
    {
        [$managerA, $groupA] = $this->makeManager();
        $groupB  = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);
        $foreign = RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => User::factory()->create()->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($managerA)->delete('/console/admin/recall/bulk', ['ids' => [$foreign->id]])
            ->assertRedirect();

        $this->assertNotNull(RecallNote::find($foreign->id));
    }

    public function test_bulk_destroy_processes_the_valid_id_when_batch_mixes_own_and_foreign_group_ids(): void
    {
        [$managerA, $groupA] = $this->makeManager();
        $groupB  = Group::create(['name' => 'B', 'owner_id' => User::factory()->create()->id]);
        $ownNote = RecallNote::create([
            'group_id' => $groupA->id, 'author_id' => $managerA->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        $foreign = RecallNote::create([
            'group_id' => $groupB->id, 'author_id' => User::factory()->create()->id, 'external_id' => 'b.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($managerA)->delete('/console/admin/recall/bulk', ['ids' => [$ownNote->id, $foreign->id]])
            ->assertRedirect();

        $this->assertNull(RecallNote::find($ownNote->id));
        $this->assertNotNull(RecallNote::find($foreign->id));
    }

    public function test_bulk_destroy_rejects_an_empty_ids_array(): void
    {
        [$manager] = $this->makeManager();

        $this->actingAs($manager)->delete('/console/admin/recall/bulk', ['ids' => []])
            ->assertSessionHasErrors('ids');
    }

    public function test_bulk_destroy_blocks_a_non_manager_even_if_recall_entitled(): void
    {
        [$manager, $group, $owner] = $this->makeManager();
        $member = $this->makeEntitledMember($group, $owner);
        $note   = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'x', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($member)->delete('/console/admin/recall/bulk', ['ids' => [$note->id]])
            ->assertRedirect('/console/dashboard');
        $this->assertNotNull(RecallNote::find($note->id));
    }

    public function test_bulk_destroy_writes_one_audit_log_per_deleted_note(): void
    {
        [$manager, $group] = $this->makeManager();
        $noteA = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'a.md',
            'title' => 'Note A', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);
        $noteB = RecallNote::create([
            'group_id' => $group->id, 'author_id' => $manager->id, 'external_id' => 'b.md',
            'title' => 'Note B', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
        ]);

        $this->actingAs($manager)->delete('/console/admin/recall/bulk', ['ids' => [$noteA->id, $noteB->id]]);

        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $manager->id, 'action' => 'recall.deleted']);
    }
}
