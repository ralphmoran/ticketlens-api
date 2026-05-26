<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the shape of the Inertia `auth` shared props and the semantics of
 * `is_team_manager` / `is_team_lead` — booleans the sidebar uses to show/hide
 * Admin nav items gated by team.manager / team.lead middleware.
 */
class InertiaSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    private const LOCKED_AUTH_KEYS = [
        'user',
        'effectivePermissions',
        'is_owner',
        'is_team_manager',
        'is_team_lead',
        'activeGrants',
        'impersonating',
        'can',
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

    public function test_is_team_lead_is_true_for_user_with_team_view_health_bit(): void
    {
        $user = User::factory()->create([
            'permissions' => Permission::team() | Permission::TeamViewHealth->value,
        ]);

        $auth = $this->actingAs($user)
            ->get('/console/dashboard')
            ->viewData('page')['props']['auth'];

        $this->assertTrue($auth['is_team_lead']);
        $this->assertFalse($auth['is_team_manager']);
    }

    public function test_is_team_lead_is_false_for_plain_team_member(): void
    {
        $user = User::factory()->create(['permissions' => Permission::team()]);

        $auth = $this->actingAs($user)
            ->get('/console/dashboard')
            ->viewData('page')['props']['auth'];

        $this->assertFalse($auth['is_team_lead']);
    }

    public function test_is_team_manager_and_lead_are_false_for_owner(): void
    {
        // The owner's effective permissions are 0x7FFFFFFF (all bits set), which
        // includes both the manager bit and the team-lead bit. Both flags must still
        // be false: the owner is a platform singleton, not a team-role participant.
        $owner = User::factory()->create(['tier' => 'owner', 'is_owner' => true]);

        $auth = $this->actingAs($owner)
            ->get('/console/dashboard')
            ->viewData('page')['props']['auth'];

        $this->assertSame(0x7FFFFFFF, $auth['effectivePermissions']);
        $this->assertFalse($auth['is_team_manager']);
        $this->assertFalse($auth['is_team_lead']);
    }

    public function test_is_team_lead_is_false_for_managers_even_with_lead_bit(): void
    {
        // Managers are managers, not leads — is_team_lead is mutually exclusive with is_team_manager.
        $user = User::factory()->create([
            'permissions' => Permission::team() | Permission::teamManagerMask() | Permission::TeamViewHealth->value,
        ]);
        Group::create(['name' => 'Managed', 'owner_id' => $user->id]);

        $auth = $this->actingAs($user)
            ->get('/console/dashboard')
            ->viewData('page')['props']['auth'];

        $this->assertTrue($auth['is_team_manager']);
        $this->assertFalse($auth['is_team_lead']);
    }
}
