<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\DevSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the dev-account matrix documented in DevSeeder so future edits
 * cannot silently drift from the role model (tier × team-manager × owner).
 */
class DevSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DevSeeder::class);
    }

    public function test_creates_exactly_five_accounts(): void
    {
        $this->assertSame(5, User::count());
    }

    public function test_drops_the_old_conflated_accounts(): void
    {
        $this->assertFalse(User::where('email', 'superadmin@test.local')->exists());
        $this->assertFalse(User::where('email', 'admin@test.local')->exists());
        $this->assertFalse(User::where('email', 'team@test.local')->exists());
    }

    public function test_free_account_is_plain_free_tier(): void
    {
        $user = User::where('email', 'free@test.local')->firstOrFail();

        $this->assertSame('free', $user->tier);
        $this->assertSame(Permission::free(), $user->permissions);
        $this->assertFalse($user->is_owner);
        $this->assertFalse($user->isTeamManager());
    }

    public function test_pro_account_has_pro_tier_no_manager_no_owner(): void
    {
        $user = User::where('email', 'pro@test.local')->firstOrFail();

        $this->assertSame('pro', $user->tier);
        $this->assertSame(Permission::pro(), $user->permissions);
        $this->assertFalse($user->is_owner);
        $this->assertFalse($user->isTeamManager());
    }

    public function test_team_member_is_a_seat_without_manager_role(): void
    {
        $user = User::where('email', 'team-member@test.local')->firstOrFail();

        $this->assertSame('team', $user->tier);
        $this->assertSame(Permission::team(), $user->permissions);
        $this->assertFalse($user->is_owner);
        $this->assertFalse($user->isTeamManager());
        $this->assertTrue($user->groups()->exists(), 'team-member must belong to a group');
    }

    public function test_team_manager_owns_a_group_and_has_manager_bits(): void
    {
        $user = User::where('email', 'team-manager@test.local')->firstOrFail();

        $this->assertSame('team', $user->tier);
        $this->assertSame(Permission::team() | Permission::teamManagerMask(), $user->permissions);
        $this->assertFalse($user->is_owner);
        $this->assertTrue($user->isTeamManager());
    }

    public function test_owner_account_is_platform_staff(): void
    {
        $user = User::where('email', 'owner@test.local')->firstOrFail();

        $this->assertSame('team', $user->tier);
        $this->assertTrue($user->is_owner);
        $this->assertTrue($user->isTeamManager());
    }

    public function test_manager_group_contains_both_manager_and_member(): void
    {
        $manager = User::where('email', 'team-manager@test.local')->firstOrFail();
        $member  = User::where('email', 'team-member@test.local')->firstOrFail();

        $group = Group::where('owner_id', $manager->id)->firstOrFail();

        $this->assertTrue($group->users()->where('users.id', $manager->id)->exists());
        $this->assertTrue($group->users()->where('users.id', $member->id)->exists());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DevSeeder::class);
        $this->seed(DevSeeder::class);

        $this->assertSame(5, User::count());
        $this->assertSame(2, Group::count(), 'Expect exactly two owned groups (manager + owner)');
    }
}
