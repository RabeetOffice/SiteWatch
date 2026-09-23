<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use GdImage;
use RuntimeException;

/**
 * Profile pictures.
 *
 * The browser crops the picture to a square before uploading; the server still treats the upload as untrusted:
 * it checks the real image type and size, centre-crops anything that is not square, and re-encodes it as a
 * 256 × 256 WebP (PNG when WebP is unavailable). Re-encoding drops metadata such as GPS location and anything
 * hidden in the original file. Files live in storage/avatars, which the web server denies; api/profile/avatar.php
 * serves them to signed-in users.
 */
final class AvatarService
{
    public const SIZE = 256;
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** Largest decoded image accepted (pixels), so a small file cannot expand into a huge bitmap. */
    private const MAX_PIXELS = 40_000_000;
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    public static function create(): self
    {
        return new self(App::db(), rtrim((string) App::config()->get('app.paths.storage'), '/\\') . DIRECTORY_SEPARATOR . 'avatars');
    }

    /**
     * Validate, square and store an uploaded picture for a user, replacing the previous one.
     *
     * @return string The new file name.
     * @throws RuntimeException With a message fit to show the user.
     */
    public function store(int $userId, string $tmpPath, ?string $previous): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Profile pictures need the PHP GD extension on this server.');
        }
        $bytes = @filesize($tmpPath);
        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('The picture could not be read. Please choose another file.');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('The picture is larger than 5 MB.');
        }
        $info = @getimagesize($tmpPath);
        if ($info === false || !in_array($info[2], self::TYPES, true)) {
            throw new RuntimeException('Use a JPEG, PNG, WebP or GIF image.');
        }
        [$width, $height] = $info;
        if ($width < 32 || $height < 32) {
            throw new RuntimeException('The picture is too small. Use one at least 32 × 32 pixels.');
        }
        if ($width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('The picture has too many pixels. Use one under 40 megapixels.');
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmpPath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmpPath),
            IMAGETYPE_GIF  => @imagecreatefromgif($tmpPath),
        };
        if (!$source instanceof GdImage) {
            throw new RuntimeException('The picture could not be read. Please choose another file.');
        }

        $out = $this->square($source, $width, $height);
        imagedestroy($source);

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            imagedestroy($out);
            throw new RuntimeException('The picture could not be saved on the server (storage/avatars is not writable).');
        }
        $webp = function_exists('imagewebp');
        $name = $userId . '-' . bin2hex(random_bytes(6)) . ($webp ? '.webp' : '.png');
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        $saved = $webp ? imagewebp($out, $path, 86) : imagepng($out, $path, 6);
        imagedestroy($out);
        if (!$saved) {
            @unlink($path);
            throw new RuntimeException('The picture could not be saved on the server.');
        }

        $this->db->update('users', ['avatar' => $name, 'updated_at' => utc_now()->format('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
        if ($previous !== null && $previous !== $name) {
            $this->deleteFile($previous);
        }
        return $name;
    }

    /**
     * Remove a user's picture (the account falls back to initials).
     */
    public function remove(int $userId, ?string $current): void
    {
        $this->db->update('users', ['avatar' => null, 'updated_at' => utc_now()->format('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
        if ($current !== null) {
            $this->deleteFile($current);
        }
    }

    /**
     * Absolute path of a stored picture, or null when the name is not one of ours or the file is gone.
     */
    public function resolve(?string $name): ?string
    {
        if ($name === null || preg_match('/^\d+-[a-f0-9]{12}\.(webp|png)$/', $name) !== 1) {
            return null;
        }
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        return is_file($path) ? $path : null;
    }

    public function deleteFile(string $name): void
    {
        $path = $this->resolve($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * URL of a user's picture, or null when they have none. The file name is part of the URL, so a new picture
     * is fetched straight away while an unchanged one stays cached.
     *
     * @param array<string, mixed> $user
     */
    public static function url(array $user): ?string
    {
        $name = $user['avatar'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }
        return base_url('api/profile/avatar.php') . '?u=' . (int) $user['id'] . '&v=' . rawurlencode(pathinfo($name, PATHINFO_FILENAME));
    }

    /**
     * Centre-crop to a square and scale to SIZE × SIZE, keeping transparency.
     */
    private function square(GdImage $source, int $width, int $height): GdImage
    {
        $side = min($width, $height);
        $srcX = intdiv($width - $side, 2);
        $srcY = intdiv($height - $side, 2);

        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        if ($out === false) {
            throw new RuntimeException('The picture could not be processed.');
        }
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $source, 0, 0, $srcX, $srcY, self::SIZE, self::SIZE, $side, $side);
        return $out;
    }
}
