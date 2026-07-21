<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserAvatarUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_url_is_null_when_no_avatar_path(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->assertNull($user->avatarUrl());
    }

    public function test_avatar_url_resolves_via_public_disk_when_avatar_path_set(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['avatar_path' => 'avatars/test-uuid.jpg']);

        $this->assertSame(
            Storage::disk('public')->url('avatars/test-uuid.jpg'),
            $user->avatarUrl(),
        );
    }
}
