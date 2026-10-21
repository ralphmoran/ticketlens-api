<?php

namespace Tests\Feature\PublicApi;

use App\Jobs\SendDigestEmail;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\DigestSchedule;
use App\Models\Feature;
use App\Models\Group;
use App\Models\RecallNote;
use App\Models\TriageSnapshot;
use App\Models\User;
use App\Models\UserFeatureGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    private function proUser(): User
    {
        return User::factory()->create(['tier' => 'pro']);
    }

    private function issueKey(User $user, array $scopes): string
    {
        $plaintext = 'tl_pk_' . bin2hex(random_bytes(20));

        ApiKey::create([
            'user_id'    => $user->id,
            'name'       => 'Integration',
            'key_prefix' => substr($plaintext, 0, ApiKey::PREFIX_LENGTH),
            'key_hash'   => ApiKey::hashToken($plaintext),
            'scopes'     => $scopes,
        ]);

        return $plaintext;
    }

    private function snapshot(User $user, string $profile, int $tickets = 1): TriageSnapshot
    {
        return TriageSnapshot::create([
            'user_id'      => $user->id,
            'profile'      => $profile,
            'tickets'      => [['ticketKey' => 'PROJ-1']],
            'ticket_count' => $tickets,
            'captured_at'  => now(),
        ]);
    }

    private function recallMember(string $groupName): array
    {
        $owner = User::query()->where('is_owner', true)->first() ?? User::factory()->create(['is_owner' => true]);
        $group = Group::create(['name' => $groupName, 'owner_id' => $owner->id]);
        $user  = $this->proUser();
        $group->users()->attach($user->id);

        $feature = Feature::firstOrCreate(['name' => 'recall'], ['bit_value' => 4096, 'label' => 'Recall', 'sort_order' => 100]);
        UserFeatureGrant::create(['user_id' => $user->id, 'feature_id' => $feature->id, 'granted_by' => $owner->id]);

        return [$user, $group];
    }

    private function schedule(User $user, bool $active = true): DigestSchedule
    {
        return DigestSchedule::create([
            'user_id'          => $user->id,
            'license_key_hash' => DigestSchedule::hashKey(bin2hex(random_bytes(8))),
            'email'            => 'dev@example.com',
            'timezone'         => 'UTC',
            'deliver_at'       => '07:00:00',
            'active'           => $active,
        ]);
    }

    private function digestPayload(): array
    {
        return [
            'profile'   => 'production',
            'staleDays' => 5,
            'summary'   => ['total' => 1, 'needsResponse' => 1, 'aging' => 0],
            'tickets'   => [['ticketKey' => 'PROJ-1', 'summary' => 'Fix cart', 'status' => 'Code Review', 'urgency' => 'needs-response']],
        ];
    }

    // ---- auth ----

    public function test_every_public_route_requires_a_key(): void
    {
        $this->getJson('/v1/public/triage/snapshots')->assertUnauthorized();
        $this->getJson('/v1/public/recall/notes')->assertUnauthorized();
        $this->postJson('/v1/public/recall/notes', [])->assertUnauthorized();
        $this->postJson('/v1/public/digest/send', [])->assertUnauthorized();
    }

    public function test_an_api_key_cannot_call_the_cli_routes(): void
    {
        $user = $this->proUser();

        $this->withToken($this->issueKey($user, ApiKey::SCOPES))
            ->getJson('/v1/recall/pull')
            ->assertUnauthorized();
    }

    // ---- triage ----

    public function test_triage_snapshots_returns_only_the_key_owners_snapshots(): void
    {
        $user = $this->proUser();
        $this->snapshot($user, 'mine');
        $this->snapshot($this->proUser(), 'theirs');

        $response = $this->withToken($this->issueKey($user, ['triage:read']))
            ->getJson('/v1/public/triage/snapshots')
            ->assertOk();

        $this->assertSame(['mine'], array_column($response->json('snapshots'), 'profile'));
    }

    public function test_triage_snapshots_can_be_filtered_by_profile(): void
    {
        $user = $this->proUser();
        $this->snapshot($user, 'alpha');
        $this->snapshot($user, 'beta');

        $response = $this->withToken($this->issueKey($user, ['triage:read']))
            ->getJson('/v1/public/triage/snapshots?profile=beta')
            ->assertOk();

        $this->assertSame(['beta'], array_column($response->json('snapshots'), 'profile'));
    }

    public function test_triage_snapshots_never_expose_share_tokens_or_license_hashes(): void
    {
        $user = $this->proUser();
        $snap = $this->snapshot($user, 'alpha');
        $snap->update(['share_token' => 'secret-share-token', 'license_key_hash' => str_repeat('a', 64)]);

        $body = $this->withToken($this->issueKey($user, ['triage:read']))
            ->getJson('/v1/public/triage/snapshots')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('secret-share-token', $body);
        $this->assertStringNotContainsString(str_repeat('a', 64), $body);
    }

    public function test_triage_snapshots_are_capped_at_the_requested_limit(): void
    {
        $user = $this->proUser();
        foreach (['a', 'b', 'c'] as $profile) {
            $this->snapshot($user, $profile);
        }

        $this->withToken($this->issueKey($user, ['triage:read']))
            ->getJson('/v1/public/triage/snapshots?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'snapshots');
    }

    public function test_triage_snapshots_require_the_triage_read_scope(): void
    {
        $user = $this->proUser();

        $this->withToken($this->issueKey($user, ['recall:read']))
            ->getJson('/v1/public/triage/snapshots')
            ->assertForbidden();
    }

    // ---- recall ----

    public function test_recall_notes_read_returns_only_the_owners_group_notes(): void
    {
        [$userA, $groupA] = $this->recallMember('A');
        [, $groupB]       = $this->recallMember('B');

        RecallNote::create(['group_id' => $groupA->id, 'author_id' => $userA->id, 'external_id' => 'a.md', 'title' => 'Group A note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x']);
        RecallNote::create(['group_id' => $groupB->id, 'author_id' => $userA->id, 'external_id' => 'b.md', 'title' => 'Group B note', 'aliases' => [], 'tickets' => [], 'tags' => [], 'sources' => [], 'body' => 'x']);

        $titles = $this->withToken($this->issueKey($userA, ['recall:read']))
            ->getJson('/v1/public/recall/notes')
            ->assertOk()
            ->json('notes.*.title');

        $this->assertSame(['Group A note'], $titles);
    }

    public function test_recall_note_create_stores_a_note_for_the_keys_owner(): void
    {
        [$user, $group] = $this->recallMember('A');

        $this->withToken($this->issueKey($user, ['recall:write']))
            ->postJson('/v1/public/recall/notes', [
                'external_id' => '1700000000000-abcdef.md',
                'title'       => 'Retry gotcha',
                'body'        => 'Needs exponential backoff.',
            ])
            ->assertOk()
            ->assertJson(['pushed' => true]);

        $note = RecallNote::sole();
        $this->assertSame($group->id, $note->group_id);
        $this->assertSame($user->id, $note->author_id);
    }

    public function test_recall_note_create_cannot_target_another_teams_group(): void
    {
        [$user]      = $this->recallMember('A');
        [, $otherGroup] = $this->recallMember('B');

        $this->withToken($this->issueKey($user, ['recall:write']))
            ->postJson('/v1/public/recall/notes', [
                'external_id' => '1700000000000-abcdef.md',
                'title'       => 't',
                'body'        => 'b',
                'group_id'    => $otherGroup->id,
            ])
            ->assertUnprocessable();

        $this->assertSame(0, RecallNote::count());
    }

    public function test_recall_note_create_is_forbidden_for_a_read_only_key(): void
    {
        [$user] = $this->recallMember('A');

        $this->withToken($this->issueKey($user, ['recall:read']))
            ->postJson('/v1/public/recall/notes', ['external_id' => '1700000000000-abcdef.md', 'title' => 't', 'body' => 'b'])
            ->assertForbidden();

        $this->assertSame(0, RecallNote::count());
    }

    // ---- digest ----

    public function test_digest_send_dispatches_one_job_per_active_schedule_of_the_owner(): void
    {
        Queue::fake();
        $user = $this->proUser();
        $this->schedule($user);
        $this->schedule($user);
        $this->schedule($user, active: false);
        $this->schedule($this->proUser());

        $this->withToken($this->issueKey($user, ['digest:send']))
            ->postJson('/v1/public/digest/send', $this->digestPayload())
            ->assertOk()
            ->assertJson(['delivered' => true, 'schedules' => 2]);

        Queue::assertPushed(SendDigestEmail::class, 2);
    }

    public function test_digest_send_inside_the_cooldown_returns_too_many_requests(): void
    {
        Queue::fake();
        $user = $this->proUser();
        $this->schedule($user)->update(['last_delivered_at' => now()->subMinute()]);

        $this->withToken($this->issueKey($user, ['digest:send']))
            ->postJson('/v1/public/digest/send', $this->digestPayload())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        Queue::assertNothingPushed();
    }

    public function test_digest_send_skips_only_the_schedules_inside_the_cooldown(): void
    {
        Queue::fake();
        $user = $this->proUser();
        $recent = $this->schedule($user);
        $recent->update(['last_delivered_at' => now()->subMinute()]);
        $stale = $this->schedule($user);
        $stale->update(['last_delivered_at' => now()->subMinutes(6)]);

        $this->withToken($this->issueKey($user, ['digest:send']))
            ->postJson('/v1/public/digest/send', $this->digestPayload())
            ->assertOk()
            ->assertJson(['schedules' => 1]);

        Queue::assertPushed(SendDigestEmail::class, 1);
        $this->assertTrue($recent->fresh()->last_delivered_at->lt(now()->subSeconds(30)));
        $this->assertTrue($stale->fresh()->last_delivered_at->gt(now()->subMinute()));
    }

    public function test_digest_send_without_an_active_schedule_returns_not_found(): void
    {
        Queue::fake();
        $user = $this->proUser();

        $this->withToken($this->issueKey($user, ['digest:send']))
            ->postJson('/v1/public/digest/send', $this->digestPayload())
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_digest_send_validates_the_payload(): void
    {
        $user = $this->proUser();
        $this->schedule($user);

        $this->withToken($this->issueKey($user, ['digest:send']))
            ->postJson('/v1/public/digest/send', ['profile' => 'production'])
            ->assertUnprocessable();
    }

    public function test_digest_send_requires_the_digest_send_scope(): void
    {
        $user = $this->proUser();
        $this->schedule($user);

        $this->withToken($this->issueKey($user, ['triage:read']))
            ->postJson('/v1/public/digest/send', $this->digestPayload())
            ->assertForbidden();
    }

    // ---- limits and audit ----

    public function test_requests_are_rate_limited_per_key(): void
    {
        $user = $this->proUser();
        $limited = $this->issueKey($user, ['triage:read']);
        $other   = $this->issueKey($user, ['triage:read']);

        foreach (range(1, 60) as $i) {
            $this->withToken($limited)->getJson('/v1/public/triage/snapshots')->assertOk();
        }

        $this->withToken($limited)->getJson('/v1/public/triage/snapshots')->assertTooManyRequests();
        $this->withToken($other)->getJson('/v1/public/triage/snapshots')->assertOk();
    }

    public function test_write_calls_are_audited_with_the_key_id(): void
    {
        Queue::fake();
        $user = $this->proUser();
        $this->schedule($user);
        $token = $this->issueKey($user, ['digest:send']);

        $this->withToken($token)->postJson('/v1/public/digest/send', $this->digestPayload())->assertOk();

        $log = AuditLog::where('action', 'api_key.call')->sole();
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame(ApiKey::sole()->id, $log->metadata['api_key_id']);
        $this->assertSame('POST', $log->metadata['method']);
        $this->assertSame(200, $log->metadata['status']);
    }

    public function test_read_calls_are_not_audited(): void
    {
        $user = $this->proUser();

        $this->withToken($this->issueKey($user, ['triage:read']))->getJson('/v1/public/triage/snapshots')->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'api_key.call')->count());
    }
}
