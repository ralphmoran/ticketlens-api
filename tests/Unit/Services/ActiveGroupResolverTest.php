<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\User;
use App\Services\ActiveGroupResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Extracted verbatim from RecallController::resolveGroup() — pins the exact
 * resolution rules: owner reads an explicit ?group_id= param (never trusts a
 * different source), everyone else resolves their own owned-or-joined group.
 */
class ActiveGroupResolverTest extends TestCase
{
    use RefreshDatabase;

    private ActiveGroupResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ActiveGroupResolver();
    }

    public function test_owner_with_group_id_param_resolves_that_group(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => 'Target', 'owner_id' => User::factory()->create()->id]);

        $request = Request::create('/console/admin/recall', 'GET', ['group_id' => $group->id]);
        $request->setUserResolver(fn () => $owner);

        $this->assertSame($group->id, $this->resolver->forRequest($request)?->id);
    }

    public function test_owner_without_group_id_param_resolves_null(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $request = Request::create('/console/admin/recall', 'GET');
        $request->setUserResolver(fn () => $owner);

        $this->assertNull($this->resolver->forRequest($request));
    }

    public function test_manager_resolves_their_owned_group(): void
    {
        $manager = User::factory()->create();
        $group   = Group::create(['name' => 'Owned', 'owner_id' => $manager->id]);

        $request = Request::create('/console/admin/recall', 'GET');
        $request->setUserResolver(fn () => $manager);

        $this->assertSame($group->id, $this->resolver->forRequest($request)?->id);
    }

    public function test_plain_member_resolves_first_joined_group(): void
    {
        $member = User::factory()->create();
        $group  = Group::create(['name' => 'Joined', 'owner_id' => User::factory()->create()->id]);
        $group->members()->attach($member->id);

        $request = Request::create('/console/admin/recall', 'GET');
        $request->setUserResolver(fn () => $member);

        $this->assertSame($group->id, $this->resolver->forRequest($request)?->id);
    }

    public function test_user_with_no_group_resolves_null(): void
    {
        $solo = User::factory()->create();

        $request = Request::create('/console/admin/recall', 'GET');
        $request->setUserResolver(fn () => $solo);

        $this->assertNull($this->resolver->forRequest($request));
    }
}
