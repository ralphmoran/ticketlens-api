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

    private function licenseFor(User $user, string $hash = 'a'): License
    {
        return License::create([
            'user_id'        => $user->id,
            'lemon_key_hash' => str_repeat($hash, 64),
            'status'         => 'active',
            'tier'           => 'pro',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/console/schedules');

        $response->assertRedirect('/console/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        // Free tier (64) has no Schedules bit (1)
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->getJson('/console/schedules');

        $response->assertStatus(403);
    }

    public function test_pro_user_sees_schedules_with_no_license(): void
    {
        // Pro = 71 (64|1|2|4): has Schedules bit
        $user = User::factory()->create(['tier' => 'pro', 'permissions' => 71]);

        $response = $this->actingAs($user)->get('/console/schedules');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Schedules')
            ->has('schedules')
            ->has('hasLicense')
            ->has('timezones')
        );
    }

    public function test_owner_can_access_schedules_without_license(): void
    {
        $owner = User::factory()->create([
            'tier'        => 'owner',
            'permissions' => 0,
            'is_owner'    => true,
        ]);

        $response = $this->actingAs($owner)->get('/console/schedules');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Schedules')
            ->where('hasLicense', true)
            ->has('schedules')
            ->has('timezones')
        );
    }

    // --- LOCK tests: existing POST behaviour (must remain stable) ---

    public function test_guest_cannot_post_schedule(): void
    {
        $response = $this->post('/console/schedules', $this->validPayload);

        $response->assertRedirect('/console/login');
    }

    public function test_user_without_permission_cannot_post_schedule(): void
    {
        $user = User::factory()->create(['tier' => 'free', 'permissions' => 64]);

        $response = $this->actingAs($user)->postJson('/console/schedules', $this->validPayload);

        $response->assertStatus(403);
    }

    // --- RED tests: new store behaviour ---

    public function test_pro_user_without_license_cannot_create_schedule(): void
    {
        $user = $this->proUser();

        $response = $this->actingAs($user)->post('/console/schedules', $this->validPayload);

        $response->assertRedirect();
        $response->assertSessionHasErrors('license');
    }

    public function test_pro_user_with_license_can_create_schedule(): void
    {
        $user    = $this->proUser();
        $license = $this->licenseFor($user);

        $response = $this->actingAs($user)->post('/console/schedules', $this->validPayload);

        $response->assertRedirect(route('console.schedules'));
        $this->assertDatabaseHas('digest_schedules', [
            'license_key_hash' => $license->lemon_key_hash,
            'email'            => 'daily@example.com',
            'timezone'         => 'America/New_York',
            'deliver_at'       => '08:00',
            'active'           => true,
        ]);
    }

    public function test_creating_schedule_is_idempotent_per_license(): void
    {
        $user    = $this->proUser();
        $license = $this->licenseFor($user);

        $this->actingAs($user)->post('/console/schedules', $this->validPayload);
        $this->actingAs($user)->post('/console/schedules', array_merge($this->validPayload, ['deliverAt' => '09:30']));

        $this->assertDatabaseCount('digest_schedules', 1);
        $this->assertDatabaseHas('digest_schedules', ['deliver_at' => '09:30']);
    }

    public function test_schedule_creation_validates_email(): void
    {
        $user = $this->proUser();
        $this->licenseFor($user);

        $response = $this->actingAs($user)->post('/console/schedules', array_merge($this->validPayload, ['email' => 'not-an-email']));

        $response->assertSessionHasErrors('email');
    }

    public function test_schedule_creation_validates_timezone(): void
    {
        $user = $this->proUser();
        $this->licenseFor($user);

        $response = $this->actingAs($user)->post('/console/schedules', array_merge($this->validPayload, ['timezone' => 'Fake/Zone']));

        $response->assertSessionHasErrors('timezone');
    }

    public function test_schedule_creation_validates_time_format(): void
    {
        $user = $this->proUser();
        $this->licenseFor($user);

        $response = $this->actingAs($user)->post('/console/schedules', array_merge($this->validPayload, ['deliverAt' => '8am']));

        $response->assertSessionHasErrors('deliverAt');
    }

    public function test_owner_cannot_create_schedule_without_license(): void
    {
        $owner = User::factory()->create([
            'tier'        => 'owner',
            'permissions' => 0,
            'is_owner'    => true,
        ]);

        $response = $this->actingAs($owner)->post('/console/schedules', $this->validPayload);

        $response->assertRedirect();
        $response->assertSessionHasErrors('license');
        $this->assertDatabaseEmpty('digest_schedules');
    }
}
