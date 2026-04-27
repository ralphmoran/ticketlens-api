<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SingleOwnerInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_first_owner_succeeds(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        $this->assertTrue($owner->fresh()->is_owner);
        $this->assertSame(1, User::where('is_owner', true)->count());
    }

    public function test_creating_second_owner_is_blocked(): void
    {
        User::factory()->create(['is_owner' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/single platform owner/i');

        User::factory()->create(['is_owner' => true]);
    }

    public function test_promoting_existing_user_to_second_owner_is_blocked(): void
    {
        User::factory()->create(['is_owner' => true]);
        $candidate = User::factory()->create(['is_owner' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/single platform owner/i');

        $candidate->update(['is_owner' => true]);
    }

    public function test_re_saving_the_owner_does_not_trip_the_guard(): void
    {
        $owner = User::factory()->create(['is_owner' => true]);

        // Touching another column on the existing owner must not trigger the guard.
        $owner->update(['name' => 'Renamed Owner']);

        $this->assertSame('Renamed Owner', $owner->fresh()->name);
        $this->assertTrue($owner->fresh()->is_owner);
    }

    public function test_creating_non_owner_users_is_unaffected(): void
    {
        User::factory()->create(['is_owner' => true]);

        $a = User::factory()->create(['is_owner' => false]);
        $b = User::factory()->create(); // factory default is is_owner=false

        $this->assertFalse($a->fresh()->is_owner);
        $this->assertFalse($b->fresh()->is_owner);
        $this->assertSame(1, User::where('is_owner', true)->count());
    }
}
