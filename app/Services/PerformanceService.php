<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Monitoring\MonitorManager;
use App\Performance\PageSpeedClient;
use App\Performance\ScreenshotCapturer;
use App\Performance\VitalsResult;
use App\Repositories\ScreenshotRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\VitalsRepository;
use App\Repositories\WebsiteRepository;
use Throwable;

/**
 * Core Web Vitals and website screenshots.
 *
 * Both are deliberately on their own schedules rather than riding the one-minute monitoring cycle. A
 * PageSpeed run drives a real browser and takes tens of seconds; the screenshot services are rate limited
 * by IP. Running either per check, across a fleet, would exhaust both quotas within the hour.
 */
final class PerformanceService
{
    /** Screenshot interval sentinel: capture whenever the website itself is checked. */
    public const SCREENSHOT_EVERY_CHECK = 0;

    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly VitalsRepository $vitals,
        private readonly ScreenshotRepository $screenshots,
        private readonly SettingsRepository $settings,
        private readonly PageSpeedClient $pagespeed,
        private readonly ScreenshotCapturer $capturer
    ) {
    }

    public static function create(): self
    {
        $db = App::db();
        $settings = App::settings();
        $userAgent = (string) App::config()->get('app.monitor.user_agent');
        $caFile = MonitorManager::caBundlePath();

        return new self(
            new WebsiteRepository($db),
            new VitalsRepository($db),
            new ScreenshotRepository($db),
            $settings,
            PageSpeedClient::create($userAgent, $caFile, $settings->getString('pagespeed_api_key')),
            ScreenshotCapturer::create($userAgent, $caFile, self::screenshotDir())
        );
    }

    public static function screenshotDir(): string
    {
        return rtrim((string) App::config()->get('app.paths.storage'), '/\\') . DIRECTORY_SEPARATOR . 'screenshots';
    }

    public function capturer(): ScreenshotCapturer
    {
        return $this->capturer;
    }

    // ------------------------------------------------------------------
    // Core Web Vitals
    // ------------------------------------------------------------------

    public function vitalsEnabled(): bool
    {
        return $this->settings->getBool('vitals_enabled');
    }

    public function vitalsIntervalHours(): int
    {
        return max(1, $this->settings->getInt('vitals_interval_hours', 24));
    }

    /** @return array<int, string> */
    public function strategies(): array
    {
        $configured = $this->settings->getString('vitals_strategies', 'both');
        return match ($configured) {
            'mobile'  => ['mobile'],
            'desktop' => ['desktop'],
            default   => PageSpeedClient::STRATEGIES,
        };
    }

    /**
     * Run PageSpeed for one website across the configured strategies and store the results.
     *
     * A failure on one strategy is recorded and does not stop the other: a URL can be analysable on
     * desktop and time out on mobile, and losing both would hide the half that worked.
     *
     * @param array<string, mixed> $website
     * @return array{ok: int, failed: int, errors: array<int, string>, screenshot: bool}
     */
    public function refreshVitals(array $website): array
    {
        $id = (int) $website['id'];
        $url = (string) $website['url'];
        $summary = ['ok' => 0, 'failed' => 0, 'errors' => [], 'screenshot' => false];

        // Only the first strategy needs to carry the screenshot back; both render the same page.
        $wantScreenshot = $this->settings->getString('screenshot_provider', ScreenshotCapturer::DEFAULT_PROVIDER) === 'pagespeed'
            && $this->settings->getBool('screenshot_enabled');

        foreach ($this->strategies() as $strategy) {
            try {
                $result = $this->pagespeed->analyse($url, $strategy, $wantScreenshot);
                $this->vitals->record($id, $result);
                $summary['ok']++;

                if ($wantScreenshot && $result->screenshot !== null && $result->screenshotMime !== null) {
                    $this->storeScreenshot($id, 'pagespeed', $result->screenshot, $result->screenshotMime);
                    $summary['screenshot'] = true;
                    $wantScreenshot = false;
                }
            } catch (Throwable $e) {
                $this->vitals->recordFailure($id, $strategy, $e->getMessage());
                $summary['failed']++;
                $summary['errors'][] = ucfirst($strategy) . ': ' . $e->getMessage();
            }
        }

        // Stamp the website even when every strategy failed, so one unanalysable URL is not retried
        // on every cron run ahead of everything else.
        $this->websites->update($id, ['vitals_checked_at' => utc_now()->format('Y-m-d H:i:s')]);
        return $summary;
    }

    /**
     * Refresh every website whose vitals are missing or stale (cron/vitals-check.php).
     *
     * @return array{checked: int, failed: int, skipped: bool}
     */
    public function refreshStaleVitals(int $limit = 50, int $pauseMs = 1000): array
    {
        if (!$this->vitalsEnabled()) {
            return ['checked' => 0, 'failed' => 0, 'skipped' => true];
        }
        $checked = 0;
        $failed = 0;
        foreach ($this->vitals->due($limit, $this->vitalsIntervalHours()) as $website) {
            try {
                $result = $this->refreshVitals($website);
                $checked++;
                if ($result['ok'] === 0) {
                    $failed++;
                }
            } catch (Throwable $e) {
                $failed++;
                App::logger('cron')->warning('Vitals check failed', ['website_id' => (int) $website['id'], 'error' => $e->getMessage()]);
            }
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000); // stay inside the PageSpeed quota
            }
        }
        return ['checked' => $checked, 'failed' => $failed, 'skipped' => false];
    }

    // ------------------------------------------------------------------
    // Screenshots
    // ------------------------------------------------------------------

    public function screenshotsEnabled(): bool
    {
        return $this->settings->getBool('screenshot_enabled');
    }

    public function screenshotProvider(): string
    {
        $provider = $this->settings->getString('screenshot_provider', ScreenshotCapturer::DEFAULT_PROVIDER);
        return in_array($provider, ScreenshotCapturer::PROVIDERS, true) ? $provider : ScreenshotCapturer::DEFAULT_PROVIDER;
    }

    public function screenshotIntervalMinutes(): int
    {
        return max(self::SCREENSHOT_EVERY_CHECK, $this->settings->getInt('screenshot_interval_minutes', 60));
    }

    /**
     * Capture one website now. Returns the stored row id.
     *
     * @param array<string, mixed> $website
     */
    public function captureScreenshot(array $website): int
    {
        $id = (int) $website['id'];
        $provider = $this->screenshotProvider();

        if ($provider === 'pagespeed') {
            // The Lighthouse render only exists as a by-product of a vitals run, so ask for one.
            $result = $this->refreshVitals($website);
            if (!$result['screenshot']) {
                $reason = $result['errors'] !== [] ? implode(' · ', $result['errors']) : 'PageSpeed returned no screenshot for this page.';
                $this->screenshots->recordFailure($id, $provider, $reason);
                throw new \RuntimeException($reason);
            }
            $latest = $this->screenshots->latest($id);
            return $latest !== null ? (int) $latest['id'] : 0;
        }

        try {
            $stored = $this->capturer->capture($id, (string) $website['url'], $provider);
        } catch (Throwable $e) {
            $this->screenshots->recordFailure($id, $provider, $e->getMessage());
            // Back off a failing capture by the normal interval rather than retrying it every cycle.
            $this->websites->update($id, ['screenshot_captured_at' => utc_now()->format('Y-m-d H:i:s')]);
            throw $e;
        }

        return $this->storeScreenshot($id, $provider, null, null, $stored);
    }

    /**
     * Capture every website whose screenshot is missing or stale.
     *
     * @return array{captured: int, failed: int, skipped: bool}
     */
    public function captureStaleScreenshots(int $limit = 50, int $pauseMs = 500): array
    {
        if (!$this->screenshotsEnabled() || $this->screenshotProvider() === 'pagespeed') {
            // With the pagespeed provider, captures happen inside the vitals run instead.
            return ['captured' => 0, 'failed' => 0, 'skipped' => true];
        }
        $interval = $this->screenshotIntervalMinutes();
        $captured = 0;
        $failed = 0;
        foreach ($this->screenshots->due($limit, max(1, $interval)) as $website) {
            try {
                $this->captureScreenshot($website);
                $captured++;
            } catch (Throwable $e) {
                $failed++;
                App::logger('cron')->warning('Screenshot failed', ['website_id' => (int) $website['id'], 'error' => $e->getMessage()]);
            }
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }
        return ['captured' => $captured, 'failed' => $failed, 'skipped' => false];
    }

    /**
     * Called from the monitoring cycle after a website has been checked, when the administrator asked for
     * a screenshot on every check.
     *
     * @param array<string, mixed> $website
     */
    public function captureAfterCheck(array $website): void
    {
        if (!$this->screenshotsEnabled()
            || $this->screenshotIntervalMinutes() !== self::SCREENSHOT_EVERY_CHECK
            || $this->screenshotProvider() === 'pagespeed') {
            return;
        }
        try {
            $this->captureScreenshot($website);
        } catch (Throwable $e) {
            App::logger('monitor')->warning('Screenshot failed', ['website_id' => (int) $website['id'], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Remove screenshots past the retention window or the per-website keep count, files first.
     *
     * @return int number of captures removed
     */
    public function pruneScreenshots(): int
    {
        $days = max(1, $this->settings->getInt('screenshot_retention_days', 14));
        $keep = max(1, $this->settings->getInt('screenshot_keep_per_website', 30));
        $cutoff = utc_now()->modify("-{$days} days")->format('Y-m-d H:i:s');

        $expired = $this->screenshots->expired($cutoff, $keep);
        if ($expired === []) {
            return 0;
        }
        $ids = [];
        foreach ($expired as $row) {
            if (!empty($row['path'])) {
                $this->capturer->delete((string) $row['path']);
            }
            $ids[] = (int) $row['id'];
        }
        return $this->screenshots->deleteMany($ids);
    }

    /**
     * @param array{path: string, mime: string, bytes: int}|null $stored
     */
    private function storeScreenshot(int $websiteId, string $provider, ?string $binary, ?string $mime, ?array $stored = null): int
    {
        if ($stored === null) {
            $stored = $this->capturer->storeRaw($websiteId, (string) $binary, (string) $mime);
        }
        $id = $this->screenshots->record($websiteId, $provider, $stored);
        $this->websites->update($websiteId, ['screenshot_captured_at' => utc_now()->format('Y-m-d H:i:s')]);
        return $id;
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * Everything the website details page and the Web Vitals page need for one website.
     *
     * @return array<string, mixed>
     */
    public function forWebsite(int $websiteId): array
    {
        $latest = $this->vitals->latestForWebsite($websiteId);
        $out = [];
        foreach (PageSpeedClient::STRATEGIES as $strategy) {
            $out[$strategy] = isset($latest[$strategy]) ? self::presentRun($latest[$strategy]) : null;
        }
        $attempt = $this->vitals->lastAttempt($websiteId);

        return [
            'enabled'         => $this->vitalsEnabled(),
            'interval_hours'  => $this->vitalsIntervalHours(),
            'runs'            => $out,
            'last_error'      => $attempt !== null && $attempt['status'] === 'failed' ? (string) $attempt['error_message'] : null,
            'last_attempt_at' => $attempt !== null ? format_datetime((string) $attempt['fetched_at']) : null,
        ];
    }

    /**
     * One stored run, with each metric rated against Google's thresholds.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentRun(array $row): array
    {
        $num = static fn (string $key): int|float|null => $row[$key] === null ? null : (str_contains($key, 'cls') ? (float) $row[$key] : (int) $row[$key]);

        $lab = [
            'ttfb' => $num('lab_ttfb'),
            'fcp'  => $num('lab_fcp'),
            'lcp'  => $num('lab_lcp'),
            'cls'  => $num('lab_cls'),
            'tbt'  => $num('lab_tbt'),
        ];
        $field = [
            'ttfb' => $num('field_ttfb'),
            'fcp'  => $num('field_fcp'),
            'lcp'  => $num('field_lcp'),
            'cls'  => $num('field_cls'),
            'inp'  => $num('field_inp'),
        ];

        return [
            'strategy'      => (string) $row['strategy'],
            'score'         => $row['performance_score'] === null ? null : (int) $row['performance_score'],
            'score_rating'  => self::scoreRating($row['performance_score'] === null ? null : (int) $row['performance_score']),
            'lab'           => self::rateAll($lab),
            'field'         => self::rateAll($field),
            'has_field'     => array_filter($field, static fn ($v) => $v !== null) !== [],
            'field_source'  => $row['field_source'] ?? null,
            'field_verdict' => $row['field_verdict'] ?? null,
            'speed_index'   => $row['lab_speed_index'] === null ? null : (int) $row['lab_speed_index'],
            'tti'           => $row['lab_tti'] === null ? null : (int) $row['lab_tti'],
            'fetched_at'    => (string) $row['fetched_at'],
            'fetched_label' => format_datetime((string) $row['fetched_at']),
        ];
    }

    /**
     * @param array<string, int|float|null> $metrics
     * @return array<string, array{value: int|float|null, rating: ?string, display: string}>
     */
    private static function rateAll(array $metrics): array
    {
        $out = [];
        foreach ($metrics as $key => $value) {
            $out[$key] = [
                'value'   => $value,
                'rating'  => VitalsResult::rate($key, $value),
                'display' => self::display($key, $value),
            ];
        }
        return $out;
    }

    public static function display(string $metric, int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }
        if ($metric === 'cls') {
            return number_format((float) $value, 3);
        }
        return format_ms((int) $value);
    }

    /** Lighthouse's own score bands: 90+ green, 50-89 amber, below 50 red. */
    public static function scoreRating(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }
        return $score >= 90 ? 'good' : ($score >= 50 ? 'needs-improvement' : 'poor');
    }
}
