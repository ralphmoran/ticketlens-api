<?php

namespace Tests\Feature\Console;

use App\Models\DigestSchedule;
use App\Models\License;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SchedulesTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'email'     => 'daily@example.com',
        'timezone'  => 'America/New_York',
        'deliverAt' => '08:00',
    ];

    private function proUser(): User
    {
        return User::factory()->create(['tier' => 'pro', 'permissions' => 71]);
    }

    private function owner(): User
    {
        return User::factory()->create(['tier' => 'owner', 'permissions' => 0, 'is_owner' => true]);
    }

    private function licenseFor(User $user, string $hash = 'a'): License
    {
        return License::create([
            'user_id'        => $user->id,
            'lemon_key_hash' => str_repeat($hash, 64),
            'status'         => 'active',
            'tier'           => 'pro',
        ]);
    }

    private function scheduleFor(User $user, array $overrides = []): DigestSchedule
    {
        return DigestSchedule::create(array_merge([
            'license_key_hash' => DigestSchedule::hashKey('owner:' . $user->id),
            'email'            => 'test@example.com',
            'timezone'         => 'UTC',
            'deliver_at'       => '07:00',
            'active'           => true,
        ], $overrides));
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/schedules')->assertRedirect('/console/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);
        $this->actingAs($user)->getJson('/console/schedules')->assertStatus(403);
    }

    public function test_pro_user_sees_schedules_page(): void
    {
        $user = $this->proUser();
        $this->actingAs($user)->get('/console/schedules')
            ->assertStatus(200)
            ->assertInertia(fn ($p) => $p
                ->component('Console/Schedules')
                ->has('schedules')
                ->has('hasLicense')
                ->has('timezones')
            );
    }

    public function test_owner_can_access_schedules(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get('/console/schedules')
            ->assertStatus(200)
            ->assertInertia(fn ($p) => $p
                ->component('Console/Schedules')
                ->where('hasLicense', true)
                ->where('schedules.data', [])
                ->where('schedules.total', 0)
                ->has('timezones')
                ->has('clients')
            );
    }

    public function test_owner_schedules_hidden_until_search(): void
    {
        $owner    = $this->owner();
        $schedule = $this->scheduleFor($owner);

        // No search — empty result
        $this->actingAs($owner)->get('/console/schedules')
            ->assertInertia(fn ($p) => $p->where('schedules.data', []));

        // With search matching the schedule email — returns results
        $this->actingAs($owner)->get('/console/schedules?scheduleSearch=' . urlencode($schedule->email))
            ->assertInertia(fn ($p) => $p
                ->where('schedules.total', 1)
                ->where('schedules.data.0.id', $schedule->id)
            );
    }

    // =========================================================================
    // STORE — LOCK (existing gates)
    // =========================================================================

    public function test_guest_cannot_post_schedule(): void
    {
        $this->post('/console/schedules', $this->validPayload)->assertRedirect('/console/login');
    }

    public function test_user_without_permission_cannot_post_schedule(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);
        $this->actingAs($user)->postJson('/console/schedules', $this->validPayload)->assertStatus(403);
    }

    public function test_pro_user_without_license_cannot_create_schedule(): void
    {
        $user = $this->proUser();
        $this->actingAs($user)->post('/console/schedules', $this->validPayload)
            ->assertRedirect()
            ->assertSessionHasErrors('license');
    }

    public function test_schedule_creation_validates_email(): void
    {
        $user = $this->proUser();
        $this->licenseFor($user);
        $this->actingAs($user)
            ->post('/console/schedules', array_merge($this->validPayload, ['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');
    }

    public function test_schedule_creation_validates_timezone(): void
    {
        $user = $this->proUser();
        $this->licenseFor($user);
        $this->actingAs($user)
            ->post('/console/schedules', array_merge($this->validPayload, ['timezone' => 'Fake/Zone']))
            ->assertSessionHasErrors('timezone');
    }

    public function test_schedule_creation_validates_time_format(): void
    {
        $user = $this->proUser();
        $this->licenseFor($user);
        $this->actingAs($user)
            ->post('/console/schedules', array_merge($this->validPayload, ['deliverAt' => '8am']))
            ->assertSessionHasErrors('deliverAt');
    }

    // =========================================================================
    // STORE — multi-schedule (RED → GREEN)
    // =========================================================================

    public function test_pro_user_with_license_can_create_schedule(): void
    {
        $user    = $this->proUser();
        $license = $this->licenseFor($user);

        $this->actingAs($user)->post('/console/schedules', $this->validPayload)
            ->assertRedirect(route('console.schedules'));

        $this->assertDatabaseHas('digest_schedules', [
            'license_key_hash' => $license->lemon_key_hash,
            'email'            => 'daily@example.com',
            'deliver_at'       => '08:00',
            'active'           => true,
        ]);
    }

    public function test_multiple_schedules_allowed_per_license(): void
    {
        $user = $this->proUser();
        $this->licenseFor($user);

        $this->actingAs($user)->post('/console/schedules', $this->validPayload);
        $this->actingAs($user)->post('/console/schedules', array_merge($this->validPayload, ['deliverAt' => '09:30']));

        $this->assertDatabaseCount('digest_schedules', 2);
    }

    public function test_owner_can_create_schedule_for_self(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/console/schedules', $this->validPayload)
            ->assertRedirect(route('console.schedules'));

        $this->assertDatabaseHas('digest_schedules', [
            'email'               => 'daily@example.com',
            'assigned_to_user_id' => null,
        ]);
    }

    public function test_owner_can_create_schedule_for_client(): void
    {
        $owner  = $this->owner();
        $client = $this->proUser();

        $this->actingAs($owner)->post('/console/schedules', array_merge($this->validPayload, [
            'clientUserId' => $client->id,
        ]))->assertRedirect(route('console.schedules'));

        $this->assertDatabaseHas('digest_schedules', [
            'email'               => 'daily@example.com',
            'assigned_to_user_id' => $client->id,
        ]);
    }

    public function test_owner_schedule_is_isolated_from_licensed_user(): void
    {
        $owner = $this->owner();
        $user  = $this->proUser();
        $this->licenseFor($user);

        $this->actingAs($owner)->post('/console/schedules', $this->validPayload);
        $this->actingAs($user)->post('/console/schedules', array_merge($this->validPayload, ['email' => 'user@example.com']));

        $this->assertDatabaseCount('digest_schedules', 2);
    }

    public function test_non_owner_cannot_assign_schedule_to_client(): void
    {
        $user   = $this->proUser();
        $client = $this->proUser();
        $this->licenseFor($user);

        $this->actingAs($user)->post('/console/schedules', array_merge($this->validPayload, [
            'clientUserId' => $client->id,
        ]))->assertRedirect(route('console.schedules'));

        // clientUserId should be silently ignored for non-owners
        $this->assertDatabaseHas('digest_schedules', ['assigned_to_user_id' => null]);
    }

    // =========================================================================
    // TOGGLE pause / resume
    // =========================================================================

    public function test_user_can_toggle_own_schedule(): void
    {
        $user     = $this->proUser();
        $license  = $this->licenseFor($user);
        $schedule = $this->scheduleFor($user, ['license_key_hash' => $license->lemon_key_hash]);

        $this->actingAs($user)->patch("/console/schedules/{$schedule->id}/toggle")
            ->assertRedirect(route('console.schedules'));

        $this->assertDatabaseHas('digest_schedules', ['id' => $schedule->id, 'active' => false]);
    }

    public function test_user_cannot_toggle_another_users_schedule(): void
    {
        $owner    = $this->owner();
        $schedule = $this->scheduleFor($owner);

        $user = $this->proUser();
        $this->licenseFor($user);

        $this->actingAs($user)->patch("/console/schedules/{$schedule->id}/toggle")
            ->assertStatus(403);
    }

    public function test_owner_can_toggle_any_schedule(): void
    {
        $owner  = $this->owner();
        $user   = $this->proUser();
        $this->licenseFor($user);

        $schedule = DigestSchedule::create([
            'license_key_hash' => str_repeat('b', 64),
            'email'            => 'x@x.com',
            'timezone'         => 'UTC',
            'deliver_at'       => '06:00',
            'active'           => true,
        ]);

        $this->actingAs($owner)->patch("/console/schedules/{$schedule->id}/toggle")
            ->assertRedirect(route('console.schedules'));

        $this->assertDatabaseHas('digest_schedules', ['id' => $schedule->id, 'active' => false]);
    }

    // =========================================================================
    // UPDATE (edit)
    // =========================================================================

    public function test_user_can_update_own_schedule(): void
    {
        $user     = $this->proUser();
        $license  = $this->licenseFor($user);
        $schedule = $this->scheduleFor($user, ['license_key_hash' => $license->lemon_key_hash]);

        $this->actingAs($user)->patch("/console/schedules/{$schedule->id}", [
            'email'     => 'new@example.com',
            'timezone'  => 'UTC',
            'deliverAt' => '10:00',
        ])->assertRedirect(route('console.schedules'));

        $this->assertDatabaseHas('digest_schedules', [
            'id'         => $schedule->id,
            'email'      => 'new@example.com',
            'deliver_at' => '10:00',
        ]);
    }

    public function test_user_cannot_update_another_users_schedule(): void
    {
        $owner    = $this->owner();
        $schedule = $this->scheduleFor($owner);

        $user = $this->proUser();
        $this->licenseFor($user);

        $this->actingAs($user)->patch("/console/schedules/{$schedule->id}", $this->validPayload)
            ->assertStatus(403);
    }

    // =========================================================================
    // DESTROY
    // =========================================================================

    public function test_user_can_delete_own_schedule(): void
    {
        $user     = $this->proUser();
        $license  = $this->licenseFor($user);
        $schedule = $this->scheduleFor($user, ['license_key_hash' => $license->lemon_key_hash]);

        $this->actingAs($user)->delete("/console/schedules/{$schedule->id}")
            ->assertRedirect(route('console.schedules'));

        $this->assertDatabaseMissing('digest_schedules', ['id' => $schedule->id]);
    }

    public function test_user_cannot_delete_another_users_schedule(): void
    {
        $owner    = $this->owner();
        $schedule = $this->scheduleFor($owner);

        $user = $this->proUser();
        $this->licenseFor($user);

        $this->actingAs($user)->delete("/console/schedules/{$schedule->id}")
            ->assertStatus(403);
    }

    public function test_owner_can_delete_any_schedule(): void
    {
        $owner    = $this->owner();
        $schedule = DigestSchedule::create([
            'license_key_hash' => str_repeat('c', 64),
            'email'            => 'y@y.com',
            'timezone'         => 'UTC',
            'deliver_at'       => '06:00',
            'active'           => true,
        ]);

        $this->actingAs($owner)->delete("/console/schedules/{$schedule->id}")
            ->assertRedirect(route('console.schedules'));

        $this->assertDatabaseMissing('digest_schedules', ['id' => $schedule->id]);
    }

    public function test_guest_cannot_delete_schedule(): void
    {
        $owner    = $this->owner();
        $schedule = $this->scheduleFor($owner);

        $this->delete("/console/schedules/{$schedule->id}")->assertRedirect('/console/login');
    }
}
