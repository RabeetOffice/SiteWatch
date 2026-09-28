<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use GdImage;
use RuntimeException;

/**
 * Logos of report brand kits.
 *
 * Uploads are untrusted: the real image type and size are checked, and the picture is re-encoded as a PNG
 * (at most MAX_WIDTH × MAX_HEIGHT, transparency kept), which drops metadata and anything hidden in the file.
 * PNG rather than WebP because the PDF renderer reads PNG natively. Files live in storage/brands, which the
 * web server denies; they are served by api/brands/logo.php (signed in) and report.php (share links).
 */
final class BrandLogoService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_WIDTH = 900;
    private const MAX_HEIGHT = 360;
    private const MAX_PIXELS = 40_000_000;
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    public function __construct(private readonly string $dir)
    {
    }

    public static function create(): self
    {
        return new self(rtrim((string) App::config()->get('app.paths.storage'), '/\\') . DIRECTORY_SEPARATOR . 'brands');
    }

    /**
     * Validate and store an uploaded logo.
     *
     * @return string The new file name.
     * @throws RuntimeException With a message fit to show the user.
     */
    public function store(int $brandId, string $tmpPath): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Logos need the PHP GD extension on this server.');
        }
        $bytes = @filesize($tmpPath);
        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('The logo could not be read. Please choose another file.');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('The logo is larger than 5 MB.');
        }
        $info = @getimagesize($tmpPath);
        if ($info === false || !in_array($info[2], self::TYPES, true)) {
            throw new RuntimeException('Use a PNG, JPEG, WebP or GIF image. For an SVG logo, export it as PNG first.');
        }
        [$width, $height] = $info;
        if ($width < 16 || $height < 16) {
            throw new RuntimeException('The logo is too small. Use one at least 16 × 16 pixels.');
        }
        if ($width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('The logo has too many pixels. Use one under 40 megapixels.');
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmpPath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmpPath),
            IMAGETYPE_GIF  => @imagecreatefromgif($tmpPath),
        };
        if (!$source instanceof GdImage) {
            throw new RuntimeException('The logo could not be read. Please choose another file.');
        }

        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $out = imagecreatetruecolor($w, $h);
        if ($out === false) {
            imagedestroy($source);
            throw new RuntimeException('The logo could not be processed.');
        }
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $source, 0, 0, 0, 0, $w, $h, $width, $height);
        imagedestroy($source);

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            imagedestroy($out);
            throw new RuntimeException('The logo could not be saved on the server (storage/brands is not writable).');
        }
        $name = $brandId . '-' . bin2hex(random_bytes(6)) . '.png';
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        $saved = imagepng($out, $path, 6);
        imagedestroy($out);
        if (!$saved) {
            @unlink($path);
            throw new RuntimeException('The logo could not be saved on the server.');
        }
        return $name;
    }

    /**
     * Absolute path of a stored logo, or null when the name is not one of ours or the file is gone.
     */
    public function resolve(?string $name): ?string
    {
        if ($name === null || preg_match('/^\d+-[a-f0-9]{12}\.png$/', $name) !== 1) {
            return null;
        }
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        return is_file($path) ? $path : null;
    }

    /** The logo as a data: URI (PDF output embeds it, so the renderer never fetches anything). */
    public function dataUri(?string $name): ?string
    {
        $path = $this->resolve($name);
        if ($path === null) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return $bytes === false ? null : 'data:image/png;base64,' . base64_encode($bytes);
    }

    public function delete(?string $name): void
    {
        $path = $this->resolve($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Send a stored logo to the browser and stop. */
    public function serve(string $path, string $etagSeed, bool $public): never
    {
        $etag = '"' . sha1($etagSeed) . '"';
        header('Content-Type: image/png');
        header('Cache-Control: ' . ($public ? 'public' : 'private') . ', max-age=604800');
        header('X-Content-Type-Options: nosniff');
        header('ETag: ' . $etag);
        if (str_contains(trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')), $etag)) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}
