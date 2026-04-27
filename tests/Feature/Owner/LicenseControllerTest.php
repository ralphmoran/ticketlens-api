<?php

namespace Tests\Feature\Owner;

use App\Mail\LicenseIssuedMail;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LicenseControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['is_owner' => true]);
    }

    // --- Index ---

    public function test_owner_can_list_licenses(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->get('/console/owner/licenses');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Console/Owner/Licenses/Index'));
    }

    public function test_non_owner_cannot_list_licenses(): void
    {
        $user = User::factory()->create(['tier' => 'enterprise', 'permissions' => 1023]);

        $response = $this->actingAs($user)->get('/console/owner/licenses');

        $response->assertRedirect('/console/dashboard');
    }

    public function test_index_filters_by_source_owner_issued(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create(['tier' => 'pro']);

        License::create([
            'user_id' => $recipient->id, 'issued_by_user_id' => $owner->id,
            'lemon_key_hash' => str_repeat('a', 64), 'status' => 'active', 'tier' => 'pro', 'seats' => 1,
        ]);
        License::create([
            'user_id' => $recipient->id, 'lemon_key_hash' => str_repeat('b', 64),
            'status' => 'active', 'tier' => 'pro', 'seats' => 1,
        ]);

        $response = $this->actingAs($owner)->get('/console/owner/licenses?source=owner_issued');

        $response->assertInertia(fn ($page) => $page->has('licenses.data', 1));
    }

    // --- Create form ---

    public function test_owner_can_view_create_form(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->get('/console/owner/licenses/create');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Licenses/Create')
            ->has('clients')
        );
    }

    // --- Store ---

    public function test_store_issues_license_and_redirects_to_created(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create();

        $response = $this->actingAs($owner)->post('/console/owner/licenses', [
            'user_id'    => $recipient->id,
            'tier'       => 'pro',
            'send_email' => true,
        ]);

        $license = License::where('user_id', $recipient->id)->firstOrFail();
        $response->assertRedirect("/console/owner/licenses/{$license->id}/created");
        $this->assertDatabaseHas('licenses', [
            'user_id'           => $recipient->id,
            'issued_by_user_id' => $owner->id,
            'tier'              => 'pro',
            'status'            => 'active',
        ]);
    }

    public function test_store_flashes_raw_key_once(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create();

        $response = $this->actingAs($owner)->post('/console/owner/licenses', [
            'user_id' => $recipient->id, 'tier' => 'pro',
        ]);

        $response->assertSessionHas('raw_key');
        $rawKey = session('raw_key');
        $this->assertStringStartsWith('TL-', $rawKey);
    }

    public function test_store_dispatches_email_when_requested(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create(['email' => 'c@test.com']);

        $this->actingAs($owner)->post('/console/owner/licenses', [
            'user_id' => $recipient->id, 'tier' => 'pro', 'send_email' => true,
        ]);

        Mail::assertQueued(LicenseIssuedMail::class);
    }

    public function test_store_skips_email_when_disabled(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create();

        $this->actingAs($owner)->post('/console/owner/licenses', [
            'user_id' => $recipient->id, 'tier' => 'pro', 'send_email' => false,
        ]);

        Mail::assertNothingQueued();
    }

    public function test_store_validates_tier(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create();

        $response = $this->actingAs($owner)->post('/console/owner/licenses', [
            'user_id' => $recipient->id, 'tier' => 'platinum',
        ]);

        $response->assertSessionHasErrors('tier');
    }

    public function test_store_rejects_past_expiry(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create();

        $response = $this->actingAs($owner)->post('/console/owner/licenses', [
            'user_id'    => $recipient->id,
            'tier'       => 'pro',
            'expires_at' => now()->subDay()->toDateString(),
        ]);

        $response->assertSessionHasErrors('expires_at');
    }

    // --- Created (one-time reveal) ---

    public function test_created_page_shows_key_when_flashed(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create();

        $this->actingAs($owner)
            ->post('/console/owner/licenses', ['user_id' => $recipient->id, 'tier' => 'pro'])
            ->assertSessionHas('raw_key');

        $license = License::first();
        $response = $this->followingRedirects()->actingAs($owner)
            ->withSession(['raw_key' => 'TL-abc123', 'emailed' => true])
            ->get("/console/owner/licenses/{$license->id}/created");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Licenses/Created')
            ->where('raw_key', 'TL-abc123')
            ->where('emailed', true)
        );
    }

    public function test_created_page_410_when_session_consumed(): void
    {
        $owner   = $this->makeOwner();
        $license = License::create([
            'user_id' => $owner->id, 'lemon_key_hash' => str_repeat('a', 64),
            'status' => 'active', 'tier' => 'pro', 'seats' => 1,
        ]);

        $response = $this->actingAs($owner)->get("/console/owner/licenses/{$license->id}/created");

        $response->assertStatus(410);
    }

    // --- Destroy (revoke) ---

    public function test_owner_can_revoke_license(): void
    {
        $owner     = $this->makeOwner();
        $recipient = User::factory()->create();
        $license   = License::create([
            'user_id' => $recipient->id, 'issued_by_user_id' => $owner->id,
            'lemon_key_hash' => str_repeat('a', 64), 'status' => 'active', 'tier' => 'pro', 'seats' => 1,
        ]);

        $response = $this->actingAs($owner)->delete("/console/owner/licenses/{$license->id}");

        $response->assertRedirect();
        $this->assertSame('cancelled', $license->fresh()->status);
    }

    public function test_non_owner_cannot_revoke_license(): void
    {
        $owner     = $this->makeOwner();
        $nonOwner  = User::factory()->create(['permissions' => 1023]);
        $recipient = User::factory()->create();
        $license   = License::create([
            'user_id' => $recipient->id, 'issued_by_user_id' => $owner->id,
            'lemon_key_hash' => str_repeat('a', 64), 'status' => 'active', 'tier' => 'pro', 'seats' => 1,
        ]);

        $response = $this->actingAs($nonOwner)->delete("/console/owner/licenses/{$license->id}");

        $response->assertRedirect('/console/dashboard');
        $this->assertSame('active', $license->fresh()->status);
    }

    // --- Owner-target protection ---
    //   The platform owner has god permissions via is_owner=true and is decoupled
    //   from the tier system. Issuing a license to the owner row would route
    //   through LicenseIssuanceService::bootstrapTeamGroup, which writes
    //   recipient->update(['tier'=>..., 'permissions'=>...]) — that would
    //   corrupt the owner sentinel values established by the migration.

    private function fabricateProtectedOwner(): User
    {
        $protected = User::factory()->create(['tier' => 'free']);
        \DB::table('users')->where('id', $protected->id)->update(['is_owner' => true]);

        return $protected->fresh();
    }

    public function test_create_recipient_list_excludes_owner(): void
    {
        $owner          = $this->makeOwner();
        $protectedOwner = $this->fabricateProtectedOwner();

        $response = $this->actingAs($owner)->get('/console/owner/licenses/create');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Console/Owner/Licenses/Create')
            ->where(
                'clients',
                fn ($clients) => collect($clients)->every(fn ($c) => $c['id'] !== $protectedOwner->id),
            ),
        );
    }

    public function test_cannot_issue_license_to_owner(): void
    {
        $owner          = $this->makeOwner();
        $protectedOwner = $this->fabricateProtectedOwner();

        $response = $this->actingAs($owner)->post('/console/owner/licenses', [
            'user_id'    => $protectedOwner->id,
            'tier'       => 'pro',
            'send_email' => false,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('licenses', ['user_id' => $protectedOwner->id]);
        // Owner sentinel values must not have been overwritten by bootstrapTeamGroup.
        $fresh = $protectedOwner->fresh();
        $this->assertSame('free', $fresh->tier, 'Owner sentinel tier must not have been mutated by issuance.');
    }
}
