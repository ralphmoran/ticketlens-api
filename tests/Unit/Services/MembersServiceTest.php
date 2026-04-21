<?php

namespace Tests\Unit\Services;

use App\Exceptions\SeatLimitReached;
use App\Models\Group;
use App\Models\License;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MembersService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MembersServiceTest extends TestCase
{
    use RefreshDatabase;

    private MembersService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->service = new MembersService(new AuditService);
    }

    private function makeManagerWithSeats(int $seats): User
    {
        $manager = User::factory()->create(['tier' => 'team', 'permissions' => 511]);
        $group   = Group::create(['name' => 'T', 'owner_id' => $manager->id]);
        $group->members()->attach($manager->id);

        License::create([
            'user_id' => $manager->id,
            'lemon_key_hash' => hash('sha256', "m-{$manager->id}-" . uniqid()),
            'status' => 'active', 'tier' => 'team', 'seats' => $seats,
        ]);

        return $manager;
    }

    public function test_invite_creates_user_when_email_not_registered(): void
    {
        $manager = $this->makeManagerWithSeats(5);

        $user = $this->service->invite($manager, 'new@example.com', 'New Member');

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'name' => 'New Member']);
        $this->assertTrue($manager->ownedGroup->members()->where('users.id', $user->id)->exists());
    }

    public function test_invite_derives_name_from_email_when_omitted(): void
    {
        $manager = $this->makeManagerWithSeats(5);

        $user = $this->service->invite($manager, 'jdoe@example.com');

        $this->assertSame('jdoe', $user->name);
    }

    public function test_invite_dispatches_password_reset_notification(): void
    {
        $manager = $this->makeManagerWithSeats(5);

        $user = $this->service->invite($manager, 'invited@example.com');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_invite_is_idempotent_for_existing_user(): void
    {
        $manager = $this->makeManagerWithSeats(5);
        $existing = User::factory()->create(['email' => 'already@example.com']);

        $user = $this->service->invite($manager, 'already@example.com');

        $this->assertSame($existing->id, $user->id);
        $this->assertTrue($manager->ownedGroup->members()->where('users.id', $existing->id)->exists());
    }

    public function test_invite_throws_SeatLimitReached_when_at_capacity(): void
    {
        $manager = $this->makeManagerWithSeats(2);
        // Seats used: manager(1) + 1 extra = 2; license.seats = 2 → at limit.
        $group = $manager->ownedGroup;
        $group->members()->attach(User::factory()->create()->id);

        $this->expectException(SeatLimitReached::class);
        $this->service->invite($manager, 'over@example.com');
    }

    public function test_SeatLimitReached_returns_409_status(): void
    {
        $e = new SeatLimitReached(5);

        $this->assertSame(409, $e->getStatusCode());
    }

    public function test_invited_user_has_unusable_random_password(): void
    {
        $manager = $this->makeManagerWithSeats(5);

        $user = $this->service->invite($manager, 'secure@example.com');

        // Password is hashed; raw cannot be known. But we can assert that a
        // subsequent login with an empty or common password fails.
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('', $user->fresh()->password));
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('password', $user->fresh()->password));
    }

    public function test_invite_writes_audit_log(): void
    {
        $manager = $this->makeManagerWithSeats(5);

        $this->service->invite($manager, 'audit@example.com');

        $user = User::where('email', 'audit@example.com')->first();
        $this->assertDatabaseHas('audit_logs', [
            'actor_id'       => $manager->id,
            'target_user_id' => $user->id,
            'action'         => 'team.member_invited',
        ]);
    }

    public function test_invite_inherits_manager_tier(): void
    {
        $manager = $this->makeManagerWithSeats(5);

        $user = $this->service->invite($manager, 'same-tier@example.com');

        $this->assertSame($manager->tier, $user->tier);
    }
}
