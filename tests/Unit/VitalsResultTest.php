<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Performance\ScreenshotCapturer;
use App\Performance\VitalsResult;
use PHPUnit\Framework\TestCase;

/**
 * Parsing of PageSpeed Insights v5 responses.
 *
 * The response shape has several traps: CrUX reports CLS multiplied by 100, field data may be present for
 * the origin but not the URL, and INP is absent entirely for most sites. Each of those is covered here,
 * because getting any of them wrong produces a plausible-looking but wrong number on the dashboard.
 */
final class VitalsResultTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function response(array $overrides = []): array
    {
        $base = [
            'lighthouseResult' => [
                'finalUrl'   => 'https://example.com/',
                'categories' => ['performance' => ['score' => 0.72]],
                'audits'     => [
                    'server-response-time'     => ['numericValue' => 412.7],
                    'first-contentful-paint'   => ['numericValue' => 1850.2],
                    'largest-contentful-paint' => ['numericValue' => 3120.9],
                    'cumulative-layout-shift'  => ['numericValue' => 0.0812345],
                    'total-blocking-time'      => ['numericValue' => 240.0],
                    'speed-index'              => ['numericValue' => 2600.0],
                    'interactive'              => ['numericValue' => 4100.0],
                ],
            ],
        ];
        return array_replace_recursive($base, $overrides);
    }

    public function testReadsLabMetricsAndConvertsTheScoreToPercent(): void
    {
        $result = VitalsResult::fromApi('mobile', self::response());

        self::assertSame('mobile', $result->strategy);
        self::assertSame(72, $result->lab['score'], 'Lighthouse reports 0-1, we store 0-100');
        self::assertSame(413, $result->lab['ttfb'], 'milliseconds are rounded, not truncated');
        self::assertSame(1850, $result->lab['fcp']);
        self::assertSame(3121, $result->lab['lcp']);
        self::assertSame(0.0812, $result->lab['cls'], 'CLS keeps four decimals');
        self::assertSame(240, $result->lab['tbt']);
        self::assertSame(2600, $result->lab['speed_index']);
        self::assertSame(4100, $result->lab['tti']);
        self::assertSame('https://example.com/', $result->finalUrl);
    }

    public function testFieldDataIsNullWhenCruxHasNothing(): void
    {
        $result = VitalsResult::fromApi('mobile', self::response());

        self::assertNull($result->fieldSource);
        self::assertNull($result->fieldVerdict);
        self::assertSame([null, null, null, null, null], array_values($result->field));
    }

    public function testPrefersFieldDataForTheExactUrl(): void
    {
        $result = VitalsResult::fromApi('mobile', self::response([
            'loadingExperience' => [
                'overall_category' => 'AVERAGE',
                'metrics' => [
                    'LARGEST_CONTENTFUL_PAINT_MS'   => ['percentile' => 2900],
                    'INTERACTION_TO_NEXT_PAINT'     => ['percentile' => 184],
                    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 12],
                    'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => ['percentile' => 760],
                    'FIRST_CONTENTFUL_PAINT_MS'     => ['percentile' => 1700],
                ],
            ],
            'originLoadingExperience' => [
                'overall_category' => 'SLOW',
                'metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 9999]],
            ],
        ]));

        self::assertSame('url', $result->fieldSource);
        self::assertSame('AVERAGE', $result->fieldVerdict);
        self::assertSame(2900, $result->field['lcp'], 'the URL dataset wins over the origin');
        self::assertSame(184, $result->field['inp']);
        self::assertSame(760, $result->field['ttfb']);
        // CrUX sends CLS as an integer scaled by 100, so 12 means 0.12 — not 12.
        self::assertSame(0.12, $result->field['cls']);
    }

    public function testFallsBackToOriginFieldDataWhenTheUrlHasNone(): void
    {
        $result = VitalsResult::fromApi('desktop', self::response([
            'loadingExperience'       => ['metrics' => []],
            'originLoadingExperience' => [
                'overall_category' => 'FAST',
                'metrics' => [
                    'LARGEST_CONTENTFUL_PAINT_MS'   => ['percentile' => 2100],
                    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 5],
                ],
            ],
        ]));

        self::assertSame('origin', $result->fieldSource);
        self::assertSame('FAST', $result->fieldVerdict);
        self::assertSame(2100, $result->field['lcp']);
        self::assertSame(0.05, $result->field['cls']);
        self::assertNull($result->field['inp'], 'a site can have field data but still no INP');
    }

    public function testAcceptsTheExperimentalInpKey(): void
    {
        $result = VitalsResult::fromApi('mobile', self::response([
            'loadingExperience' => [
                'metrics' => ['EXPERIMENTAL_INTERACTION_TO_NEXT_PAINT' => ['percentile' => 310]],
            ],
        ]));

        self::assertSame(310, $result->field['inp']);
    }

    public function testMissingAuditsBecomeNullRatherThanZero(): void
    {
        // A failed audit is omitted entirely; storing 0 would read as a perfect result.
        $result = VitalsResult::fromApi('mobile', [
            'lighthouseResult' => ['categories' => ['performance' => ['score' => null]], 'audits' => []],
        ]);

        self::assertNull($result->lab['score']);
        self::assertNull($result->lab['lcp']);
        self::assertNull($result->lab['cls']);
    }

    public function testRatesMetricsAgainstGooglesThresholds(): void
    {
        self::assertSame('good', VitalsResult::rate('lcp', 2500), 'the boundary itself is still good');
        self::assertSame('needs-improvement', VitalsResult::rate('lcp', 2501));
        self::assertSame('poor', VitalsResult::rate('lcp', 4001));

        self::assertSame('good', VitalsResult::rate('inp', 200));
        self::assertSame('poor', VitalsResult::rate('inp', 501));

        self::assertSame('good', VitalsResult::rate('cls', 0.1));
        self::assertSame('needs-improvement', VitalsResult::rate('cls', 0.2));
        self::assertSame('poor', VitalsResult::rate('cls', 0.3));

        self::assertNull(VitalsResult::rate('lcp', null), 'nothing measured, nothing to rate');
        self::assertNull(VitalsResult::rate('made_up', 10));
    }

    // ------------------------------------------------------------------
    // Screenshot decoding
    // ------------------------------------------------------------------

    public function testDecodesTheLighthouseScreenshotOnlyWhenAsked(): void
    {
        $jpeg = "\xFF\xD8\xFF" . str_repeat('x', 200);
        $response = self::response([
            'lighthouseResult' => ['audits' => ['final-screenshot' => ['details' => ['data' => 'data:image/jpeg;base64,' . base64_encode($jpeg)]]]],
        ]);

        self::assertNull(VitalsResult::fromApi('mobile', $response)->screenshot, 'not decoded unless requested');

        $withShot = VitalsResult::fromApi('mobile', $response, true);
        self::assertSame($jpeg, $withShot->screenshot);
        self::assertSame('image/jpeg', $withShot->screenshotMime);
    }

    public function testRejectsAScreenshotThatIsNotAnImageDataUri(): void
    {
        foreach (['data:text/html;base64,' . base64_encode('<script>'), 'https://evil.test/x.jpg', 'not a uri'] as $payload) {
            $result = VitalsResult::fromApi('mobile', self::response([
                'lighthouseResult' => ['audits' => ['final-screenshot' => ['details' => ['data' => $payload]]]],
            ]), true);
            self::assertNull($result->screenshot, $payload);
        }
    }

    public function testScreenshotMimeIsDetectedFromTheBytesNotTheHeader(): void
    {
        self::assertSame('image/jpeg', ScreenshotCapturer::detectMime("\xFF\xD8\xFF" . str_repeat('a', 20)));
        self::assertSame('image/png', ScreenshotCapturer::detectMime("\x89PNG\r\n\x1a\n" . str_repeat('a', 20)));
        self::assertSame('image/webp', ScreenshotCapturer::detectMime('RIFF' . '____' . 'WEBP' . str_repeat('a', 20)));
        // An HTML error page mislabelled as an image must never reach disk.
        self::assertNull(ScreenshotCapturer::detectMime('<!doctype html><html><body>Rate limited</body></html>'));
        self::assertNull(ScreenshotCapturer::detectMime('tiny'));
    }

    public function testExtensionFollowsTheMimeType(): void
    {
        self::assertSame('jpg', ScreenshotCapturer::extension('image/jpeg'));
        self::assertSame('png', ScreenshotCapturer::extension('image/png'));
        self::assertSame('webp', ScreenshotCapturer::extension('image/webp'));
    }
}
