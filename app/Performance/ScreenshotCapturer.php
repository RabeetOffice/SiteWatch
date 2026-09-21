<?php

declare(strict_types=1);

namespace App\Performance;

use App\Domains\HttpClient;
use RuntimeException;

/**
 * Captures a picture of a monitored website through a free rendering service, and stores it under
 * storage/screenshots (outside the web root — images are served by api/websites/screenshot.php).
 *
 * Rendering a page needs a real browser, which shared PHP hosting does not have, so every provider here is
 * an external service that renders on our behalf:
 *
 *  - pagespeed  The final render Lighthouse already produced during a Core Web Vitals run. No extra request
 *               and no extra service, but only as fresh as the vitals schedule.
 *  - mshots     WordPress.com's mShots. Free, no key. It renders asynchronously: the first request for a new
 *               URL returns a placeholder, and the real image appears on a later attempt.
 *  - thumio     thum.io's free endpoint. Free, no key, renders immediately, rate limited by IP.
 *
 * All three receive the monitored URL, so they learn which sites are being watched.
 */
final class ScreenshotCapturer
{
    public const PROVIDERS = ['pagespeed', 'mshots', 'thumio'];
    public const DEFAULT_PROVIDER = 'mshots';

    /** Anything larger than this is a rendering service misbehaving, not a screenshot. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    /** mShots answers immediately with a grey placeholder while it renders; those are near-identical in size. */
    private const MSHOTS_PLACEHOLDER_MAX_BYTES = 2200;

    private const MIME_BY_SIGNATURE = [
        "\xFF\xD8\xFF"     => 'image/jpeg',
        "\x89PNG\r\n\x1a\n" => 'image/png',
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $storageDir,
        private readonly int $width = 1280,
        private readonly int $height = 800
    ) {
    }

    public static function create(string $userAgent, ?string $caFile, string $storageDir, int $width = 1280, int $height = 800): self
    {
        return new self(new HttpClient($userAgent, $caFile, 30), $storageDir, $width, $height);
    }

    /**
     * Fetch a screenshot of $url from $provider and write it to disk.
     *
     * @return array{path: string, mime: string, bytes: int} path is relative to the screenshot directory
     */
    public function capture(int $websiteId, string $url, string $provider): array
    {
        $endpoint = match ($provider) {
            'mshots' => 'https://s0.wp.com/mshots/v1/' . rawurlencode($url) . '?w=' . $this->width . '&h=' . $this->height,
            'thumio' => 'https://image.thum.io/get/width/' . $this->width . '/crop/' . $this->height . '/noanimate/' . $url,
            default  => throw new RuntimeException('The "' . $provider . '" provider cannot capture on demand; it is produced by a Core Web Vitals run.'),
        };

        $response = $this->http->get($endpoint, ['Accept: image/*'], self::MAX_BYTES);
        if ($response['error'] !== null) {
            throw new RuntimeException('Screenshot request failed: ' . mb_substr($response['error'], 0, 300));
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('The screenshot service returned HTTP ' . $response['status'] . '.');
        }

        $body = $response['body'];
        $mime = self::detectMime($body);

        // mShots renders asynchronously. Until it has, it answers with something that is not the page —
        // sometimes a tiny grey placeholder, sometimes not an image at all — so both shapes mean "queued",
        // not "broken". Reporting it as a failure to fetch an image would send the administrator hunting
        // for a problem that resolves itself on the next capture.
        if ($provider === 'mshots' && ($mime === null || strlen($body) <= self::MSHOTS_PLACEHOLDER_MAX_BYTES)) {
            throw new RuntimeException('mShots has queued this page and is still rendering it. The next capture should succeed.');
        }
        if ($mime === null) {
            throw new RuntimeException('The screenshot service did not return an image.');
        }

        return $this->store($websiteId, $body, $mime);
    }

    /**
     * Store bytes that were produced elsewhere — currently the Lighthouse final render.
     *
     * @return array{path: string, mime: string, bytes: int}
     */
    public function storeRaw(int $websiteId, string $binary, string $mime): array
    {
        if ($binary === '' || strlen($binary) > self::MAX_BYTES) {
            throw new RuntimeException('The screenshot is empty or too large to store.');
        }
        return $this->store($websiteId, $binary, $mime);
    }

    /**
     * Absolute path of a stored screenshot, or null when the relative path escapes the storage directory
     * or the file is gone. The path comes from the database, but it is still resolved rather than trusted.
     */
    public function resolve(string $relativePath): ?string
    {
        if ($relativePath === '' || preg_match('#^[0-9]+/[A-Za-z0-9._-]+$#', $relativePath) !== 1) {
            return null;
        }
        $full = $this->storageDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath($full);
        $base = realpath($this->storageDir);
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($real) ? $real : null;
    }

    public function delete(string $relativePath): void
    {
        $path = $this->resolve($relativePath);
        if ($path !== null) {
            @unlink($path);
        }
    }

    public function storageDir(): string
    {
        return $this->storageDir;
    }

    // ------------------------------------------------------------------
    // Disk
    // ------------------------------------------------------------------

    /**
     * @return array{path: string, mime: string, bytes: int}
     */
    private function store(int $websiteId, string $binary, string $mime): array
    {
        $dir = $this->storageDir . DIRECTORY_SEPARATOR . $websiteId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the screenshot directory. Check that storage/ is writable.');
        }

        $name = utc_now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . self::extension($mime);
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (@file_put_contents($full, $binary, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the screenshot to storage/screenshots.');
        }
        @chmod($full, 0664);

        return ['path' => $websiteId . '/' . $name, 'mime' => $mime, 'bytes' => strlen($binary)];
    }

    /**
     * Identify the image from its own bytes rather than the Content-Type header, so a service that
     * mislabels an error page cannot get it written to disk as an image.
     */
    public static function detectMime(string $body): ?string
    {
        if (strlen($body) < 12) {
            return null;
        }
        foreach (self::MIME_BY_SIGNATURE as $signature => $mime) {
            if (str_starts_with($body, $signature)) {
                return $mime;
            }
        }
        if (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    public static function extension(string $mime): string
    {
        return match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }
}
