<?php

namespace Tests\Unit\Services;

use App\Exceptions\InsufficientSeats;
use App\Exceptions\InvalidGrantRecipient;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
use App\Models\UserFeatureGrant;
use App\Services\AuditService;
use App\Services\TeamAccessService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeamAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // RefreshDatabase's ephemeral test DB starts with an empty `features`
        // table (FeatureSeeder is a seeder, not a migration) — team_manage_members
        // and team_manage_seats must exist for grant()/revoke() to resolve them.
        $this->seed(FeatureSeeder::class);
        $this->service = new TeamAccessService(new AuditService);
    }

    private function owner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    // --- ensureGroupExists ---

    public function test_ensure_group_exists_creates_a_group_when_none_exists(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);

        $group = $this->service->ensureGroupExists($user);

        $this->assertDatabaseHas('groups', ['id' => $group->id, 'owner_id' => $user->id]);
        $this->assertTrue($group->members()->where('users.id', $user->id)->exists());
    }

    public function test_ensure_group_exists_is_idempotent(): void
    {
        $user = User::factory()->create(['tier' => 'pro']);

        $first  = $this->service->ensureGroupExists($user);
        $second = $this->service->ensureGroupExists($user->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Group::where('owner_id', $user->id)->count());
    }

    public function test_ensure_group_exists_never_mutates_tier_or_permissions(): void
    {
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 2119]);

        $this->service->ensureGroupExists($user);

        $fresh = $user->fresh();
        $this->assertSame('pro', $fresh->tier);
        $this->assertSame(2119, $fresh->permissions);
    }

    // --- grant() validation ---

    public function test_grant_rejects_the_platform_owner_as_recipient(): void
    {
        // Only one owner account can exist at all — the rejection is tested
        // by the owner attempting to grant Team Access to themself.
        $owner = $this->owner();

        $this->expectException(InvalidGrantRecipient::class);
        $this->service->grant($owner, $owner, 3);
    }

    public function test_grant_rejects_team_tier_recipient(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'team']);

        $this->expectException(InvalidGrantRecipient::class);
        $this->service->grant($owner, $recipient, 3);
    }

    public function test_grant_rejects_enterprise_tier_recipient(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'enterprise']);

        $this->expectException(InvalidGrantRecipient::class);
        $this->service->grant($owner, $recipient, 3);
    }

    public function test_grant_rejects_a_recipient_who_already_belongs_to_another_group(): void
    {
        // A user who is a rank-and-file member of someone else's group must
        // never also become the owner of a second group — User::team()
        // assumes exactly one group membership; a second would make it
        // ambiguous which group's data (e.g. Jira config) the user sees.
        $owner = $this->owner();
        $existingGroupOwner = User::factory()->create(['tier' => 'pro']);
        $this->service->grant($owner, $existingGroupOwner, 3);
        $recipient = User::factory()->create(['tier' => 'pro']);
        $existingGroupOwner->fresh()->ownedGroup->members()->attach($recipient->id);

        $this->expectException(InvalidGrantRecipient::class);
        $this->service->grant($owner, $recipient->fresh(), 3);
    }

    public function test_grant_rejects_seats_below_two(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);

        $this->expectException(InsufficientSeats::class);
        $this->service->grant($owner, $recipient, 1);
    }

    // --- grant() behavior ---

    public function test_grant_bootstraps_a_group_for_a_free_client(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $this->service->grant($owner, $recipient, 3);

        $recipient->refresh();
        $this->assertNotNull($recipient->ownedGroup);
        $this->assertSame('free', $recipient->tier, 'tier must never change from Team Access alone');
    }

    public function test_grant_creates_a_dedicated_addon_license_for_a_licenseless_free_client(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'free']);

        $this->service->grant($owner, $recipient, 4);

        $this->assertDatabaseHas('licenses', [
            'user_id' => $recipient->id,
            'tier'    => 'free',
            'seats'   => 4,
            'status'  => 'active',
            'granted_by_owner_as_addon' => true,
        ]);
    }

    public function test_grant_never_mutates_an_existing_real_pro_license(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);
        $realLicense = License::create([
            'user_id' => $recipient->id,
            'lemon_key_hash' => hash('sha256', 'real-key'),
            'status' => 'active', 'tier' => 'pro', 'seats' => 1,
        ]);

        $this->service->grant($owner, $recipient, 3);

        $this->assertSame(1, $realLicense->fresh()->seats, 'the real paid license must be untouched');
        $this->assertFalse((bool) $realLicense->fresh()->granted_by_owner_as_addon);
        $this->assertDatabaseHas('licenses', [
            'user_id' => $recipient->id,
            'seats'   => 3,
            'granted_by_owner_as_addon' => true,
        ]);
    }

    public function test_grant_creates_both_team_manage_grants_with_shared_expiry(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);
        $expiresAt = Carbon::now()->addDays(14);

        $this->service->grant($owner, $recipient, 3, $expiresAt);

        $grants = UserFeatureGrant::where('user_id', $recipient->id)->active()->with('feature')->get();
        $names  = $grants->pluck('feature.name')->sort()->values()->all();

        $this->assertSame(['team_manage_members', 'team_manage_seats'], $names);
        foreach ($grants as $grant) {
            $this->assertSame($expiresAt->toDateTimeString(), $grant->expires_at->toDateTimeString());
        }
    }

    public function test_grant_never_grants_broader_bits_than_team_manage_members_and_seats(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro', 'permissions' => 2119]);

        $this->service->grant($owner, $recipient, 3);

        // Raw permissions column must be untouched — team-manage access lives
        // entirely in UserFeatureGrant, never baked into users.permissions.
        $this->assertSame(2119, $recipient->fresh()->permissions);
    }

    public function test_grant_is_idempotent_and_reuses_the_same_group(): void
    {
        // Each call uses a freshly-hydrated recipient, matching how two
        // independent HTTP requests would each re-fetch the model via
        // route-model-binding rather than reusing one stale in-memory instance.
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);

        $this->service->grant($owner, $recipient->fresh(), 3);
        $firstGroupId = $recipient->fresh()->ownedGroup->id;

        $this->service->grant($owner, $recipient->fresh(), 5);
        $secondGroupId = $recipient->fresh()->ownedGroup->id;

        $this->assertSame($firstGroupId, $secondGroupId);
        $this->assertSame(1, Group::where('owner_id', $recipient->id)->count());
    }

    public function test_grant_writes_audit_log(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);

        $this->service->grant($owner, $recipient, 3);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $recipient->id,
            'action'         => 'team_access.granted',
        ]);
    }

    public function test_invitee_cannot_invite_others(): void
    {
        // A rank-and-file invitee (attached to the group but never owner_id)
        // never receives team_manage_members/seats, regardless of who invited them.
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);
        $this->service->grant($owner, $recipient, 3);

        $invitee = User::factory()->create(['tier' => 'pro']);
        $recipient->fresh()->ownedGroup->members()->attach($invitee->id);

        $this->assertSame(0, UserFeatureGrant::where('user_id', $invitee->id)->active()->count());
        $this->assertNull($invitee->ownedGroup);
    }

    // --- revoke() ---

    public function test_revoke_soft_revokes_both_grants(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);
        $this->service->grant($owner, $recipient, 3);

        $this->service->revoke($owner, $recipient);

        $this->assertSame(0, UserFeatureGrant::where('user_id', $recipient->id)->active()->count());
    }

    public function test_revoke_cancels_only_the_addon_license(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);
        $realLicense = License::create([
            'user_id' => $recipient->id,
            'lemon_key_hash' => hash('sha256', 'real-key-2'),
            'status' => 'active', 'tier' => 'pro', 'seats' => 1,
        ]);
        $this->service->grant($owner, $recipient, 3);

        $this->service->revoke($owner, $recipient);

        $this->assertSame('active', $realLicense->fresh()->status, 'the real paid license must never be cancelled by this');
        $this->assertDatabaseHas('licenses', [
            'user_id' => $recipient->id,
            'granted_by_owner_as_addon' => true,
            'status'  => 'cancelled',
        ]);
    }

    public function test_revoke_never_deletes_the_group_or_its_members(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);
        $this->service->grant($owner, $recipient, 3);
        $group = $recipient->fresh()->ownedGroup;
        $invitee = User::factory()->create();
        $group->members()->attach($invitee->id);

        $this->service->revoke($owner, $recipient);

        $this->assertDatabaseHas('groups', ['id' => $group->id]);
        $this->assertTrue($group->members()->where('users.id', $invitee->id)->exists());
    }

    public function test_revoke_writes_audit_log(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);
        $this->service->grant($owner, $recipient, 3);

        $this->service->revoke($owner, $recipient);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $owner->id,
            'target_user_id' => $recipient->id,
            'action'         => 'team_access.revoked',
        ]);
    }

    public function test_re_grant_after_revoke_restores_access_to_the_same_group(): void
    {
        $owner = $this->owner();
        $recipient = User::factory()->create(['tier' => 'pro']);
        $this->service->grant($owner, $recipient, 3);
        $originalGroupId = $recipient->fresh()->ownedGroup->id;

        $this->service->revoke($owner, $recipient->fresh());
        $this->service->grant($owner, $recipient->fresh(), 3);

        $this->assertSame($originalGroupId, $recipient->fresh()->ownedGroup->id);
        $this->assertSame(2, UserFeatureGrant::where('user_id', $recipient->id)->active()->count());
    }
}
