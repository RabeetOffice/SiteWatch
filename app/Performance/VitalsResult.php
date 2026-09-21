<?php

declare(strict_types=1);

namespace App\Performance;

/**
 * One PageSpeed run, reduced to the numbers SiteWatch stores and shows.
 *
 * Lab values always exist; field values only when CrUX has enough traffic for the URL or its origin.
 * INP is field-only — Lighthouse has no user to interact with the page, so Total Blocking Time is kept
 * as its lab stand-in rather than being presented as INP.
 */
final class VitalsResult
{
    /** Google's "good / needs improvement / poor" boundaries. Times are milliseconds; CLS is unitless. */
    public const THRESHOLDS = [
        'lcp'  => [2500, 4000],
        'inp'  => [200, 500],
        'cls'  => [0.1, 0.25],
        'ttfb' => [800, 1800],
        'fcp'  => [1800, 3000],
        'tbt'  => [200, 600],
    ];

    /**
     * @param array<string, int|float|null> $lab   score, ttfb, fcp, lcp, cls, tbt, speed_index, tti
     * @param array<string, int|float|null> $field ttfb, fcp, lcp, cls, inp
     */
    public function __construct(
        public readonly string $strategy,
        public readonly array $lab,
        public readonly array $field,
        public readonly ?string $fieldSource,
        public readonly ?string $fieldVerdict,
        public readonly ?string $screenshot,
        public readonly ?string $screenshotMime,
        public readonly ?string $finalUrl,
        public readonly string $fetchedAt
    ) {
    }

    /**
     * @param array<string, mixed> $data Decoded PageSpeed Insights v5 response.
     */
    public static function fromApi(string $strategy, array $data, bool $wantScreenshot = false): self
    {
        $lighthouse = is_array($data['lighthouseResult'] ?? null) ? $data['lighthouseResult'] : [];
        $audits = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];

        $score = $lighthouse['categories']['performance']['score'] ?? null;
        $lab = [
            'score'       => is_numeric($score) ? (int) round((float) $score * 100) : null,
            'ttfb'        => self::auditMs($audits, 'server-response-time'),
            'fcp'         => self::auditMs($audits, 'first-contentful-paint'),
            'lcp'         => self::auditMs($audits, 'largest-contentful-paint'),
            'cls'         => self::auditValue($audits, 'cumulative-layout-shift'),
            'tbt'         => self::auditMs($audits, 'total-blocking-time'),
            'speed_index' => self::auditMs($audits, 'speed-index'),
            'tti'         => self::auditMs($audits, 'interactive'),
        ];

        // Prefer field data for this exact URL; fall back to the origin, which is far more often populated.
        $fieldSource = null;
        $experience = [];
        if (self::hasMetrics($data['loadingExperience'] ?? null)) {
            $fieldSource = 'url';
            $experience = $data['loadingExperience'];
        } elseif (self::hasMetrics($data['originLoadingExperience'] ?? null)) {
            $fieldSource = 'origin';
            $experience = $data['originLoadingExperience'];
        }
        $metrics = is_array($experience['metrics'] ?? null) ? $experience['metrics'] : [];

        $field = [
            'ttfb' => self::percentile($metrics, 'EXPERIMENTAL_TIME_TO_FIRST_BYTE'),
            'fcp'  => self::percentile($metrics, 'FIRST_CONTENTFUL_PAINT_MS'),
            'lcp'  => self::percentile($metrics, 'LARGEST_CONTENTFUL_PAINT_MS'),
            'inp'  => self::percentile($metrics, 'INTERACTION_TO_NEXT_PAINT')
                   ?? self::percentile($metrics, 'EXPERIMENTAL_INTERACTION_TO_NEXT_PAINT'),
            // CrUX reports CLS multiplied by 100 so that it fits an integer field.
            'cls'  => self::clsScore($metrics),
        ];

        $verdict = $experience['overall_category'] ?? null;

        $screenshot = null;
        $mime = null;
        if ($wantScreenshot) {
            [$screenshot, $mime] = self::decodeScreenshot($audits);
        }

        return new self(
            strategy: $strategy,
            lab: $lab,
            field: $field,
            fieldSource: $fieldSource,
            fieldVerdict: is_string($verdict) ? mb_substr($verdict, 0, 10) : null,
            screenshot: $screenshot,
            screenshotMime: $mime,
            finalUrl: isset($lighthouse['finalUrl']) && is_string($lighthouse['finalUrl']) ? $lighthouse['finalUrl'] : null,
            fetchedAt: utc_now()->format('Y-m-d H:i:s')
        );
    }

    /** Whether a value is good, needs improvement, or poor against Google's published boundaries. */
    public static function rate(string $metric, int|float|null $value): ?string
    {
        if ($value === null || !isset(self::THRESHOLDS[$metric])) {
            return null;
        }
        [$good, $poor] = self::THRESHOLDS[$metric];
        if ($value <= $good) {
            return 'good';
        }
        return $value <= $poor ? 'needs-improvement' : 'poor';
    }

    // ------------------------------------------------------------------
    // Parsing helpers
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $audits */
    private static function auditMs(array $audits, string $id): ?int
    {
        $value = $audits[$id]['numericValue'] ?? null;
        return is_numeric($value) ? max(0, (int) round((float) $value)) : null;
    }

    /** @param array<string, mixed> $audits */
    private static function auditValue(array $audits, string $id): ?float
    {
        $value = $audits[$id]['numericValue'] ?? null;
        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    /** @param array<string, mixed> $metrics */
    private static function percentile(array $metrics, string $key): ?int
    {
        $value = $metrics[$key]['percentile'] ?? null;
        return is_numeric($value) ? max(0, (int) round((float) $value)) : null;
    }

    /** @param array<string, mixed> $metrics */
    private static function clsScore(array $metrics): ?float
    {
        $value = $metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ?? null;
        return is_numeric($value) ? round((float) $value / 100, 4) : null;
    }

    private static function hasMetrics(mixed $experience): bool
    {
        return is_array($experience) && is_array($experience['metrics'] ?? null) && $experience['metrics'] !== [];
    }

    /**
     * Lighthouse returns the final render as a data URI. Only the image types Lighthouse actually produces
     * are accepted, so an unexpected payload is dropped rather than written to disk.
     *
     * @param array<string, mixed> $audits
     * @return array{0: ?string, 1: ?string} raw bytes, mime type
     */
    private static function decodeScreenshot(array $audits): array
    {
        $uri = $audits['final-screenshot']['details']['data'] ?? null;
        if (!is_string($uri) || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,#', $uri, $m)) {
            return [null, null];
        }
        $binary = base64_decode(substr($uri, strlen($m[0])), true);
        if ($binary === false || $binary === '' || strlen($binary) > 4 * 1024 * 1024) {
            return [null, null];
        }
        return [$binary, $m[1]];
    }
}
