<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BehaviorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['tier' => 'free', 'permissions' => 1], $attributes))->fresh();
    }

    public function test_page_renders_current_values_and_choices(): void
    {
        $user = $this->user(['idle_warning_minutes' => 10, 'session_message_style' => 'plain']);

        $this->actingAs($user)->get('/console/behavior')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Console/Behavior')
                ->where('behavior.idle_warning_minutes', 10)
                ->where('behavior.session_message_style', 'plain')
                ->where('behavior.idle_warning_choices', [5, 10, 60])
                ->where('behavior.message_styles', ['playful', 'plain'])
            );
    }

    public function test_idle_warning_minutes_is_cast_to_integer(): void
    {
        $this->assertSame('integer', $this->user()->getCasts()['idle_warning_minutes']);
    }

    public function test_new_user_defaults_to_server_timing_and_playful_messages(): void
    {
        $user = $this->user();

        $this->assertNull($user->idle_warning_minutes);
        $this->assertSame('playful', $user->session_message_style);
    }

    #[DataProvider('validMinutes')]
    public function test_update_persists_each_allowed_idle_choice(?int $minutes): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patch('/console/behavior', ['idle_warning_minutes' => $minutes, 'session_message_style' => 'plain'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($minutes, $user->fresh()->idle_warning_minutes);
        $this->assertSame('plain', $user->fresh()->session_message_style);
    }

    public static function validMinutes(): array
    {
        return ['5 min' => [5], '10 min' => [10], '1 hour' => [60], 'server default' => [null]];
    }

    #[DataProvider('invalidMinutes')]
    public function test_update_rejects_values_outside_the_allowed_choices($minutes): void
    {
        $user = $this->user(['idle_warning_minutes' => 10]);

        $this->actingAs($user)
            ->patch('/console/behavior', ['idle_warning_minutes' => $minutes, 'session_message_style' => 'playful'])
            ->assertSessionHasErrors('idle_warning_minutes');

        $this->assertSame(10, $user->fresh()->idle_warning_minutes);
    }

    public static function invalidMinutes(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'unlisted' => [7], 'huge' => [100000], 'text' => ['abc'], 'array' => [[5]]];
    }

    public function test_update_rejects_unknown_message_style(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patch('/console/behavior', ['idle_warning_minutes' => 5, 'session_message_style' => 'rude'])
            ->assertSessionHasErrors('session_message_style');

        $this->assertSame('playful', $user->fresh()->session_message_style);
    }

    public function test_update_requires_message_style(): void
    {
        $this->actingAs($this->user())
            ->patch('/console/behavior', ['idle_warning_minutes' => 5])
            ->assertSessionHasErrors('session_message_style');
    }

    public function test_update_only_touches_the_acting_user(): void
    {
        $user  = $this->user();
        $other = $this->user(['idle_warning_minutes' => 60]);

        $this->actingAs($user)
            ->patch('/console/behavior', ['idle_warning_minutes' => 5, 'session_message_style' => 'plain', 'user_id' => $other->id, 'id' => $other->id]);

        $this->assertSame(60, $other->fresh()->idle_warning_minutes);
        $this->assertSame('playful', $other->fresh()->session_message_style);
    }

    public function test_guests_cannot_read_or_write(): void
    {
        $this->get('/console/behavior')->assertRedirect();
        $this->patch('/console/behavior', ['idle_warning_minutes' => 5, 'session_message_style' => 'plain'])->assertRedirect();
    }

    public function test_update_is_throttled(): void
    {
        $user = $this->user();

        foreach (range(1, 10) as $_) {
            $this->actingAs($user)->patch('/console/behavior', ['idle_warning_minutes' => 5, 'session_message_style' => 'plain'])->assertRedirect();
        }

        $this->actingAs($user)->patch('/console/behavior', ['idle_warning_minutes' => 5, 'session_message_style' => 'plain'])->assertStatus(429);
    }

    public function test_shared_auth_user_exposes_the_two_settings(): void
    {
        $user = $this->user(['idle_warning_minutes' => 5, 'session_message_style' => 'plain']);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.idle_warning_minutes', 5)
                ->where('auth.user.session_message_style', 'plain')
            );
    }

    public function test_server_session_lifetime_share_is_unchanged_by_the_setting(): void
    {
        $user = $this->user(['idle_warning_minutes' => 5]);

        $this->actingAs($user)->get('/console/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.session_lifetime', (int) config('session.lifetime') * 60));
    }
}
