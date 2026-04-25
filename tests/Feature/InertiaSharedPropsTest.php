<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the shape of the Inertia `auth` shared props and the semantics of
 * `is_team_manager` — the boolean the sidebar uses to hide Admin nav items
 * that would otherwise redirect through `team.manager` middleware.
 */
class InertiaSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    private const LOCKED_AUTH_KEYS = [
        'user',
        'effectivePermissions',
        'is_owner',
        'is_team_manager',
        'activeGrants',
        'impersonating',
    ];

    public function test_auth_shared_props_expose_the_locked_key_set(): void
    {
        $user = User::factory()->create(['permissions' => Permission::free()]);

        $auth = $this->actingAs($user)
            ->get('/console/dashboard')
            ->viewData('page')['props']['auth'] ?? null;

        $this->assertIsArray($auth, 'Inertia auth props missing from response');
        $this->assertSame(self::LOCKED_AUTH_KEYS, array_keys($auth));
    }

    public function test_is_team_manager_is_false_when_user_has_bit_but_no_owned_group(): void
    {
        $user = User::factory()->create([
            'permissions' => Permission::team() | Permission::teamManagerMask(),
        ]);

        $auth = $this->actingAs($user)
            ->get('/console/dashboard')
            ->viewData('page')['props']['auth'];

        $this->assertFalse($auth['is_team_manager']);
    }

    public function test_is_team_manager_is_false_when_user_owns_group_but_lacks_bit(): void
    {
        $user = User::factory()->create(['permissions' => Permission::team()]);
        Group::create(['name' => 'Orphaned', 'owner_id' => $user->id]);

        $auth = $this->actingAs($user)
            ->get('/console/dashboard')
            ->viewData('page')['props']['auth'];

        $this->assertFalse($auth['is_team_manager']);
    }

    public function test_is_team_manager_is_true_when_user_has_bit_and_owns_group(): void
    {
        $user = User::factory()->create([
            'permissions' => Permission::team() | Permission::teamManagerMask(),
        ]);
        Group::create(['name' => 'Managed', 'owner_id' => $user->id]);

        $auth = $this->actingAs($user)
            ->get('/console/dashboard')
            ->viewData('page')['props']['auth'];

        $this->assertTrue($auth['is_team_manager']);
    }

    public function test_is_team_manager_is_false_for_guests(): void
    {
        $auth = $this->get('/console/login')
            ->viewData('page')['props']['auth'];

        $this->assertFalse($auth['is_team_manager']);
        $this->assertNull($auth['user']);
    }
}
