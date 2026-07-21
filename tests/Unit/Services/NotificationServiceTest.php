<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\License;
use App\Models\RecallNote;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(NotificationService::class);
    }

    // team(2687) | teamManagerMask(384) = 3071 — matches RecallControllerTest::makeManager()
    private function makeManager(): array
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 3071]);
        $group   = Group::create(['name' => "Team {$manager->id}", 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);
        return [$manager, $group];
    }

    private function makeNote(Group $group, User $author, string $status = 'unverified'): RecallNote
    {
        return RecallNote::create([
            'group_id' => $group->id, 'author_id' => $author->id, 'external_id' => uniqid('n', true) . '.md',
            'title' => 'Note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x',
            'status' => $status,
        ]);
    }

    private function makeLicense(User $user, array $overrides = []): License
    {
        return License::create(array_merge([
            'user_id'        => $user->id,
            'lemon_key_hash' => hash('sha256', uniqid('lic', true)),
            'status'         => 'active',
            'tier'           => 'pro',
            'expires_at'     => null,
        ], $overrides));
    }

    // ---- Recall category ----

    public function test_manager_sees_unverified_recall_notes_in_their_group(): void
    {
        [$manager, $group] = $this->makeManager();
        $this->makeNote($group, $manager, 'unverified');
        $this->makeNote($group, $manager, 'unverified');
        $this->makeNote($group, $manager, 'verified');

        $result = $this->service->pendingFor($manager);

        $this->assertTrue($result['categories']['recall']['available']);
        $this->assertSame(2, $result['categories']['recall']['count']);
        $this->assertCount(2, $result['categories']['recall']['items']);
    }

    public function test_non_manager_gets_null_recall_category(): void
    {
        $member = User::factory()->create(['tier' => 'pro']);
        $group  = Group::create(['name' => 'G', 'owner_id' => User::factory()->create()->id]);
        $group->members()->attach($member->id);
        $this->makeNote($group, $member, 'unverified');

        $result = $this->service->pendingFor($member);

        $this->assertNull($result['categories']['recall']);
    }

    public function test_solo_user_with_no_group_gets_null_recall_category(): void
    {
        $solo = User::factory()->create(['tier' => 'free']);

        $result = $this->service->pendingFor($solo);

        $this->assertNull($result['categories']['recall']);
    }

    public function test_owner_gets_null_recall_category(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $result = $this->service->pendingFor($owner);

        $this->assertNull($result['categories']['recall']);
    }

    public function test_manager_never_sees_a_different_groups_notes(): void
    {
        [$manager]      = $this->makeManager();
        $otherOwner     = User::factory()->create();
        $otherGroup     = Group::create(['name' => 'Other', 'owner_id' => $otherOwner->id]);
        $this->makeNote($otherGroup, $otherOwner, 'unverified');

        $result = $this->service->pendingFor($manager);

        $this->assertSame(0, $result['categories']['recall']['count']);
    }

    public function test_recall_item_list_capped_at_twenty_but_count_reflects_true_total(): void
    {
        [$manager, $group] = $this->makeManager();
        for ($i = 0; $i < 25; $i++) {
            $this->makeNote($group, $manager, 'unverified');
        }

        $result = $this->service->pendingFor($manager);

        $this->assertSame(25, $result['categories']['recall']['count']);
        $this->assertCount(20, $result['categories']['recall']['items']);
    }

    // ---- License category ----

    public function test_license_non_active_status_triggers(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $this->makeLicense($user, ['status' => 'cancelled']);

        $result = $this->service->pendingFor($user);

        $this->assertTrue($result['categories']['license']['available']);
        $this->assertTrue($result['categories']['license']['triggered']);
    }

    public function test_license_active_far_future_does_not_trigger(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $this->makeLicense($user, ['expires_at' => now()->addDays(100)]);

        $result = $this->service->pendingFor($user);

        $this->assertFalse($result['categories']['license']['triggered']);
    }

    public function test_license_expiring_within_fourteen_days_triggers(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $this->makeLicense($user, ['expires_at' => now()->addDays(13)]);

        $result = $this->service->pendingFor($user);

        $this->assertTrue($result['categories']['license']['triggered']);
    }

    public function test_license_expiring_exactly_fourteen_days_triggers(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $this->makeLicense($user, ['expires_at' => now()->addDays(14)]);

        $result = $this->service->pendingFor($user);

        $this->assertTrue($result['categories']['license']['triggered']);
    }

    public function test_license_expiring_in_fifteen_days_does_not_trigger(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $this->makeLicense($user, ['expires_at' => now()->addDays(15)]);

        $result = $this->service->pendingFor($user);

        $this->assertFalse($result['categories']['license']['triggered']);
    }

    public function test_license_active_with_no_expiry_does_not_trigger(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);
        $this->makeLicense($user, ['expires_at' => null]);

        $result = $this->service->pendingFor($user);

        $this->assertFalse($result['categories']['license']['triggered']);
    }

    public function test_user_with_no_license_gets_unavailable_license_category(): void
    {
        $user = User::factory()->create(['tier' => 'free']);

        $result = $this->service->pendingFor($user);

        $this->assertFalse($result['categories']['license']['available']);
        $this->assertFalse($result['categories']['license']['triggered']);
    }

    // ---- Deferred categories (v2) ----

    public function test_invites_and_workflow_failures_are_marked_coming_soon(): void
    {
        $user = User::factory()->create(['tier' => 'free']);

        $result = $this->service->pendingFor($user);

        $this->assertFalse($result['categories']['invites']['available']);
        $this->assertTrue($result['categories']['invites']['comingSoon']);
        $this->assertFalse($result['categories']['workflowFailures']['available']);
        $this->assertTrue($result['categories']['workflowFailures']['comingSoon']);
    }

    // ---- Top-level count ----

    public function test_top_level_count_sums_recall_and_triggered_license(): void
    {
        [$manager, $group] = $this->makeManager();
        $this->makeNote($group, $manager, 'unverified');
        $this->makeNote($group, $manager, 'unverified');
        $this->makeLicense($manager, ['status' => 'cancelled']);

        $result = $this->service->pendingFor($manager);

        $this->assertSame(3, $result['count']);
    }

    public function test_top_level_count_is_zero_when_nothing_pending(): void
    {
        $user = User::factory()->create(['tier' => 'free']);

        $result = $this->service->pendingFor($user);

        $this->assertSame(0, $result['count']);
    }
}
