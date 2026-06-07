<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_tier_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name'        => 'Attacker',
            'email'       => 'attacker@example.com',
            'password'    => bcrypt('secret'),
            'tier'        => 'team',       // must be silently ignored
            'permissions' => 65535,        // must be silently ignored
        ]);

        $this->assertNotEquals('team', $user->tier);
        $this->assertNotEquals(65535, $user->permissions);
    }

    public function test_permissions_is_not_mass_assignable(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 0]);

        $user->fill(['permissions' => 65535, 'tier' => 'team']);

        $this->assertNotEquals(65535, $user->permissions);
        $this->assertNotEquals('team', $user->tier);
    }

    public function test_tier_and_permissions_are_settable_via_direct_assignment(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 0]);

        $user->tier        = 'pro';
        $user->permissions = 2119;
        $user->save();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'tier' => 'pro', 'permissions' => 2119]);
    }
}
