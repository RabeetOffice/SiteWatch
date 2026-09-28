<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClientReportRenderer;
use App\Services\ClientReportService;
use PHPUnit\Framework\TestCase;

/**
 * Client reports: brand validation, share-link state, periods, the plain-language summary, colour helpers and
 * the SVG charts. Nothing here touches the database.
 */
final class ClientReportTest extends TestCase
{
    private static function service(): ClientReportService
    {
        // The tested methods use no repositories.
        return (new \ReflectionClass(ClientReportService::class))->newInstanceWithoutConstructor();
    }

    public function testBrandValidationNormalisesInput(): void
    {
        $v = self::service()->validateBrand([
            'name' => '  Acme  ', 'primary_color' => '#1d4ed8', 'accent_color' => '', 'website' => 'acme.example',
            'email' => 'hi@acme.example', 'white_label' => '1',
        ]);
        self::assertSame([], $v['errors']);
        self::assertSame('Acme', $v['data']['name']);
        self::assertSame('#1D4ED8', $v['data']['primary_color']);
        self::assertSame(ClientReportService::DEFAULT_ACCENT, $v['data']['accent_color']);
        self::assertSame('https://acme.example', $v['data']['website']);
        self::assertSame(1, $v['data']['white_label']);
        self::assertNull($v['data']['phone']);
    }

    public function testBrandValidationRejectsBadValues(): void
    {
        $v = self::service()->validateBrand(['name' => '', 'primary_color' => 'orange', 'email' => 'nope', 'website' => 'javascript:alert(1)']);
        self::assertArrayHasKey('name', $v['errors']);
        self::assertArrayHasKey('primary_color', $v['errors']);
        self::assertArrayHasKey('email', $v['errors']);
        self::assertArrayHasKey('website', $v['errors']);
    }

    public function testLinkStatus(): void
    {
        $future = utc_now()->modify('+1 day')->format('Y-m-d H:i:s');
        $past = utc_now()->modify('-1 minute')->format('Y-m-d H:i:s');
        self::assertSame('active', ClientReportService::linkStatus(['is_active' => 1, 'expires_at' => null]));
        self::assertSame('active', ClientReportService::linkStatus(['is_active' => 1, 'expires_at' => $future]));
        self::assertSame('expired', ClientReportService::linkStatus(['is_active' => 1, 'expires_at' => $past]));
        self::assertSame('disabled', ClientReportService::linkStatus(['is_active' => 0, 'expires_at' => $future]));
    }

    public function testPeriods(): void
    {
        $service = self::service();
        $today = utc_now()->setTimezone(app_timezone());

        $r = $service->periodRange(['period' => '30d']);
        self::assertSame(30, $r['days']);
        self::assertSame($today->format('Y-m-d'), $r['to']);

        $r = $service->periodRange(['period' => 'last_month']);
        self::assertSame($today->modify('first day of last month')->format('Y-m-d'), $r['from']);
        self::assertSame($today->modify('last day of last month')->format('Y-m-d'), $r['to']);

        $r = $service->periodRange(['period' => 'custom', 'date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        self::assertSame(['2026-01-01', '2026-01-31', 31, '1 Jan – 31 Jan 2026'], [$r['from'], $r['to'], $r['days'], $r['label']]);
    }

    public function testRangeLabel(): void
    {
        self::assertSame('5 Mar 2026', ClientReportService::rangeLabel('2026-03-05', '2026-03-05'));
        self::assertSame('28 Dec 2025 – 3 Jan 2026', ClientReportService::rangeLabel('2025-12-28', '2026-01-03'));
    }

    public function testVerdict(): void
    {
        self::assertSame('No data yet', ClientReportService::verdict(null, 0)['label']);
        self::assertSame('Excellent', ClientReportService::verdict(99.95, 100)['label']);
        self::assertSame('Good', ClientReportService::verdict(99.6, 100)['label']);
        self::assertSame('Fair', ClientReportService::verdict(98.5, 100)['label']);
        self::assertSame('Needs attention', ClientReportService::verdict(90.0, 100)['label']);
    }

    public function testNarrative(): void
    {
        $range = ['days' => 30, 'label' => '1 Sep – 30 Sep 2026'];
        $summary = ['websites' => 3, 'checks' => 1200, 'uptime_label' => '99.95%', 'downtime_label' => '12 min', 'avg_response' => 640, 'avg_response_label' => '640 ms'];
        $text = ClientReportService::narrative($summary, $range, 2);
        self::assertStringContainsString('your 3 websites 1,200 times', $text);
        self::assertStringContainsString('2 confirmed outages adding up to 12 min', $text);
        self::assertStringContainsString('with no confirmed outages', ClientReportService::narrative($summary, $range, 0));
        self::assertStringContainsString('No websites', ClientReportService::narrative(['websites' => 0] + $summary, $range, 0));
    }

    public function testDecodeHelpers(): void
    {
        self::assertSame([3, 5], ClientReportService::decodeIds('[3,"5",3]'));
        self::assertSame([], ClientReportService::decodeIds(null));
        self::assertSame(['summary', 'incidents'], ClientReportService::decodeSections('["incidents","bogus","summary"]'));
        self::assertSame(array_keys(ClientReportService::SECTIONS), ClientReportService::decodeSections(null));
    }

    public function testColourHelpers(): void
    {
        self::assertSame('#FFFFFF', ClientReportRenderer::onColor('#0F172A'));
        self::assertSame('#0F172A', ClientReportRenderer::onColor('#FDE047'));
        self::assertSame('#FFFFFF', ClientReportRenderer::tint('#123456', 1.0));
        self::assertSame('#000000', ClientReportRenderer::shade('#123456', 1.0));
        // A light brand colour is darkened before it is used as text on white.
        self::assertNotSame('#FDE047', ClientReportRenderer::readable('#FDE047'));
        self::assertSame('#1D4ED8', ClientReportRenderer::readable('#1D4ED8'));
    }

    public function testChartsAreSvgDataUris(): void
    {
        $daily = [
            ['date' => '2026-09-01', 'uptime' => 100.0, 'avg' => 500.0],
            ['date' => '2026-09-02', 'uptime' => null, 'avg' => null],
            ['date' => '2026-09-03', 'uptime' => 98.5, 'avg' => 900.0],
        ];
        $a = ClientReportRenderer::availabilityChart($daily);
        self::assertTrue($a['has_data']);
        self::assertLessThan(98.5, $a['floor']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $a['src']);
        self::assertStringContainsString('<rect', (string) base64_decode(substr($a['src'], 26)));

        $r = ClientReportRenderer::responseChart($daily, '#1D4ED8');
        self::assertTrue($r['has_data']);
        self::assertGreaterThanOrEqual(900, $r['max']);
        self::assertSame('2026-09-01', $r['fastest']['date']);
        self::assertSame('2026-09-03', $r['slowest']['date']);

        $empty = ClientReportRenderer::responseChart([['date' => '2026-09-01', 'uptime' => null, 'avg' => null]], '#000000');
        self::assertFalse($empty['has_data']);
    }

    public function testPdfFileName(): void
    {
        self::assertSame(
            'acme-publishing-monthly-report-2026-09-01_2026-09-30.pdf',
            ClientReportRenderer::fileName('Acme Publishing', 'Monthly report!', ['from' => '2026-09-01', 'to' => '2026-09-30'])
        );
    }
}
