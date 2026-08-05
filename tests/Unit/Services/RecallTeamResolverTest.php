<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\User;
use App\Services\RecallTeamResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Distinct from ActiveGroupResolver (console/admin, owner-impersonation via
 * ?group_id=) — this resolves which of the AUTHENTICATED user's own group
 * memberships a CLI Recall push/pull/profile-sync should target, always
 * scoped to $user->groups(), never a global lookup.
 */
class RecallTeamResolverTest extends TestCase
{
    use RefreshDatabase;

    private RecallTeamResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RecallTeamResolver();
    }

    public function test_no_group_id_resolves_owned_group_over_membership(): void
    {
        $user = User::factory()->create();
        $owned = Group::create(['name' => 'Owned', 'owner_id' => $user->id]);
        $joined = Group::create(['name' => 'Joined', 'owner_id' => User::factory()->create()->id]);
        $joined->members()->attach($user->id);

        $this->assertSame($owned->id, $this->resolver->resolveForUser($user, null)?->id);
    }

    public function test_no_group_id_and_no_owned_group_resolves_first_membership(): void
    {
        $user = User::factory()->create();
        $joined = Group::create(['name' => 'Joined', 'owner_id' => User::factory()->create()->id]);
        $joined->members()->attach($user->id);

        $this->assertSame($joined->id, $this->resolver->resolveForUser($user, null)?->id);
    }

    public function test_no_group_and_no_membership_resolves_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($this->resolver->resolveForUser($user, null));
    }

    public function test_explicit_group_id_resolves_that_membership_even_when_not_owned(): void
    {
        $user   = User::factory()->create();
        $owned  = Group::create(['name' => 'Owned', 'owner_id' => $user->id]);
        $joined = Group::create(['name' => "Team Manager's Team", 'owner_id' => User::factory()->create()->id]);
        $joined->members()->attach($user->id);

        $resolved = $this->resolver->resolveForUser($user, $joined->id);

        $this->assertSame($joined->id, $resolved?->id);
        $this->assertNotSame($owned->id, $resolved?->id);
    }

    public function test_explicit_group_id_the_user_does_not_belong_to_resolves_null(): void
    {
        $user = User::factory()->create();
        Group::create(['name' => 'Owned', 'owner_id' => $user->id]);
        $foreign = Group::create(['name' => 'Someone Elses Team', 'owner_id' => User::factory()->create()->id]);

        $this->assertNull($this->resolver->resolveForUser($user, $foreign->id));
    }

    public function test_explicit_group_id_never_leaks_a_group_the_user_is_not_a_member_of(): void
    {
        $user = User::factory()->create();
        $otherOwner = User::factory()->create();
        $foreignGroup = Group::create(['name' => 'Foreign Team', 'owner_id' => $otherOwner->id]);

        $this->assertNull($this->resolver->resolveForUser($user, $foreignGroup->id));
    }

    public function test_a_nonexistent_group_id_resolves_null_not_an_error(): void
    {
        $user = User::factory()->create();

        $this->assertNull($this->resolver->resolveForUser($user, 999999));
    }

    public function test_list_for_user_returns_owned_and_joined_groups_with_role(): void
    {
        $user   = User::factory()->create();
        $owned  = Group::create(['name' => 'Owned', 'owner_id' => $user->id]);
        $joined = Group::create(['name' => 'Joined', 'owner_id' => User::factory()->create()->id]);
        $joined->members()->attach($user->id);

        $list = $this->resolver->listForUser($user);

        $this->assertCount(2, $list);
        $roles = $list->pluck('role', 'id')->all();
        $this->assertSame('owner', $roles[$owned->id]);
        $this->assertSame('member', $roles[$joined->id]);
    }

    public function test_list_for_user_with_no_groups_returns_empty(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $this->resolver->listForUser($user));
    }
}
