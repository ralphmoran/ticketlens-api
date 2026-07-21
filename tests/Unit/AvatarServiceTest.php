<?php

namespace Tests\Unit;

use App\Exceptions\AvatarProcessingException;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarServiceTest extends TestCase
{
    use RefreshDatabase;

    private AvatarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AvatarService();
        Storage::fake('public');
    }

    // ---- Pure helpers — no fixtures needed ----

    public function test_assert_dimensions_within_limit_rejects_oversized(): void
    {
        $this->expectException(AvatarProcessingException::class);

        $this->service->assertDimensionsWithinLimit(9000, 100);
    }

    public function test_assert_dimensions_within_limit_accepts_normal_size(): void
    {
        $this->service->assertDimensionsWithinLimit(2000, 2000);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_crop_to_square_crops_wide_image_to_shorter_dimension(): void
    {
        $image = imagecreatetruecolor(100, 50);

        $square = $this->service->cropToSquare($image);

        $this->assertSame(50, imagesx($square));
        $this->assertSame(50, imagesy($square));
    }

    public function test_crop_to_square_crops_tall_image_to_shorter_dimension(): void
    {
        $image = imagecreatetruecolor(50, 100);

        $square = $this->service->cropToSquare($image);

        $this->assertSame(50, imagesx($square));
        $this->assertSame(50, imagesy($square));
    }

    public function test_apply_orientation_rotates_90_for_orientation_6(): void
    {
        $image = imagecreatetruecolor(20, 10); // wide rectangle

        $rotated = $this->service->applyOrientation($image, 6);

        $this->assertSame(10, imagesx($rotated));
        $this->assertSame(20, imagesy($rotated));
    }

    public function test_apply_orientation_leaves_image_unchanged_for_orientation_1(): void
    {
        $image = imagecreatetruecolor(20, 10);

        $unchanged = $this->service->applyOrientation($image, 1);

        $this->assertSame(20, imagesx($unchanged));
        $this->assertSame(10, imagesy($unchanged));
    }

    // ---- store() / destroy() — real fake-generated images ----

    public function test_store_resizes_to_256_square_and_returns_relative_path(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);
        $file = UploadedFile::fake()->image('avatar.jpg', 400, 300);

        $path = $this->service->store($file, $user);

        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('avatars/', $path);
        [$width, $height] = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame(256, $width);
        $this->assertSame(256, $height);
    }

    public function test_store_deletes_previous_avatar_on_reupload(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);
        $first = $this->service->store(UploadedFile::fake()->image('one.jpg', 300, 300), $user);
        $user->update(['avatar_path' => $first]);

        $second = $this->service->store(UploadedFile::fake()->image('two.jpg', 300, 300), $user->fresh());

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_store_rejects_dimensions_over_the_limit(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->expectException(AvatarProcessingException::class);

        $this->service->store(UploadedFile::fake()->image('huge.jpg', 9000, 9000), $user);
    }

    public function test_destroy_removes_existing_avatar_file(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);
        $path = $this->service->store(UploadedFile::fake()->image('avatar.jpg', 300, 300), $user);
        $user->update(['avatar_path' => $path]);

        $this->service->destroy($user->fresh());

        Storage::disk('public')->assertMissing($path);
    }

    public function test_destroy_is_a_no_op_when_no_avatar_exists(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->service->destroy($user);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_store_does_not_delete_old_avatar_when_new_write_fails(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/existing.jpg']);

        Storage::shouldReceive('disk')->with('public')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andReturn(false);
        Storage::shouldReceive('exists')->never();
        Storage::shouldReceive('delete')->never();

        $this->expectException(AvatarProcessingException::class);

        $this->service->store(UploadedFile::fake()->image('two.jpg', 300, 300), $user);
    }
}
