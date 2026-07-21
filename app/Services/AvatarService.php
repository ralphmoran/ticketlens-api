<?php

namespace App\Services;

use App\Exceptions\AvatarProcessingException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AvatarService
{
    // GD decodes the full source image into memory before any resize happens —
    // capped well above TARGET_SIZE but far below MAX_DIMENSION's old 8000
    // (each 8000x8000 truecolor buffer was ~256MB, and up to 4 are live at once).
    private const MAX_DIMENSION = 3000;
    private const TARGET_SIZE   = 256;

    public function store(UploadedFile $file, User $user): string
    {
        $realPath = $file->getRealPath();
        $imageInfo = getimagesize($realPath);

        if ($imageInfo === false) {
            throw new AvatarProcessingException('Unable to read image.');
        }

        [$width, $height, $type] = $imageInfo;
        $this->assertDimensionsWithinLimit($width, $height);

        $source = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($realPath),
            IMAGETYPE_PNG  => imagecreatefrompng($realPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($realPath),
            default        => false,
        };

        if ($source === false) {
            throw new AvatarProcessingException('Unable to process image.');
        }

        $oriented = $this->applyOrientation($source, $type === IMAGETYPE_JPEG ? $this->readOrientation($realPath) : 1);
        $square   = $this->cropToSquare($oriented);
        $resized  = imagescale($square, self::TARGET_SIZE, self::TARGET_SIZE);

        if ($resized === false) {
            $this->destroyHandles($source, $oriented, $square);
            throw new AvatarProcessingException('Unable to process image.');
        }

        ob_start();
        imagejpeg($resized);
        $contents = ob_get_clean();

        $this->destroyHandles($source, $oriented, $square, $resized);

        $path = 'avatars/' . Str::uuid() . '.jpg';

        // Write the new file and confirm it before touching the old one — Storage::put()
        // returns false on failure rather than throwing, so an unchecked call here would
        // silently leave the user with a deleted avatar and a DB row pointing at nothing.
        if (Storage::disk('public')->put($path, $contents) === false) {
            throw new AvatarProcessingException('Unable to save image.');
        }

        $this->destroy($user);

        return $path;
    }

    /**
     * Destroys each handle at most once — applyOrientation() returns the same
     * reference unchanged when no rotation is needed, so $source and $oriented
     * are frequently the same object.
     */
    private function destroyHandles(\GdImage ...$handles): void
    {
        $seen = [];
        foreach ($handles as $handle) {
            $id = spl_object_id($handle);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            imagedestroy($handle);
        }
    }

    public function destroy(User $user): void
    {
        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }
    }

    public function assertDimensionsWithinLimit(int $width, int $height): void
    {
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new AvatarProcessingException('Image dimensions exceed the allowed maximum.');
        }
    }

    public function cropToSquare(\GdImage $image): \GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $size   = min($width, $height);
        $x      = intdiv($width - $size, 2);
        $y      = intdiv($height - $size, 2);

        $square = imagecreatetruecolor($size, $size);
        imagecopy($square, $image, 0, 0, $x, $y, $size, $size);

        return $square;
    }

    public function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated === false) {
            throw new AvatarProcessingException('Unable to process image.');
        }

        return $rotated;
    }

    private function readOrientation(string $path): int
    {
        $exif = @exif_read_data($path);

        return is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
    }
}
