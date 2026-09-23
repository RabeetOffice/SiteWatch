<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConnectorService;
use PHPUnit\Framework\TestCase;

final class ConnectorSmallItemsTest extends TestCase
{
    public function testGzippedReportsAreDecodedWithinTheSizeLimit(): void
    {
        $json = json_encode(['v' => 1, 'reason' => 'heartbeat', 'snapshot' => ['plugins' => array_fill(0, 500, ['name' => 'Plugin', 'version' => '1.0'])]]);
        self::assertSame($json, ConnectorService::gunzipReport((string) gzencode((string) $json, 6)));
        self::assertNull(ConnectorService::gunzipReport('not gzip at all'));
        self::assertNull(ConnectorService::gunzipReport(''));
    }

    public function testCompressedReportThatExpandsTooFarIsRefused(): void
    {
        // About 5 KB compressed, 5 MB once expanded: refused without expanding it all.
        $bomb = (string) gzencode(str_repeat('a', 5 * 1024 * 1024), 9);
        self::assertLessThan(20_000, strlen($bomb));
        self::assertNull(ConnectorService::gunzipReport($bomb));
    }

    public function testDailyReportsBecomeHistoryRows(): void
    {
        $end = time() - 60;
        $row = ConnectorService::dailyRow([
            'generated_at' => $end,
            'performance'  => ['requests' => 40, 'since' => $end - 86400, 'contexts' => [
                'front' => ['p50' => 310, 'p95' => 900, 'avg_queries' => 28.24],
                'admin' => ['p50' => 250, 'p95' => 400],
            ]],
            'php_warnings' => ['enabled' => true, 'since' => $end - 80000, 'total' => 12, 'places' => 3, 'entries' => [
                ['type' => 'Deprecated', 'count' => 7], ['type' => 'Warning', 'count' => 5],
            ]],
        ]);
        self::assertNotNull($row);
        self::assertSame(gmdate('Y-m-d H:i:s', $end - 86400), $row['period_start'], 'The earlier of the two periods.');
        self::assertSame(gmdate('Y-m-d H:i:s', $end), $row['period_end']);
        self::assertSame([40, 310, 900, 250, 400, 28.2], [$row['requests'], $row['p50_front'], $row['p95_front'], $row['p50_admin'], $row['p95_admin'], $row['queries_front']]);
        self::assertSame([12, 3, 7], [$row['warnings_total'], $row['warnings_places'], $row['deprecations']]);

        $perfOnly = ConnectorService::dailyRow(['generated_at' => $end, 'performance' => ['requests' => 3, 'contexts' => []], 'php_warnings' => ['enabled' => false]]);
        self::assertNull($perfOnly['warnings_total'], 'Collection off is not the same as zero warnings.');
        self::assertNull($perfOnly['p50_front']);

        self::assertNull(ConnectorService::dailyRow(['generated_at' => $end, 'performance' => ['requests' => 0], 'php_warnings' => ['enabled' => false]]), 'Nothing measured, nothing stored.');
        self::assertNull(ConnectorService::dailyRow(['performance' => ['requests' => 5]]), 'A report without a time is not stored.');
    }

    public function testProbeNoteOnlyForRecentServerErrorsSeenInsideWordPress(): void
    {
        $note = ConnectorService::probeNote(['healthy' => false, 'probe_seen_ago' => 120, 'probe_status' => 503]);
        self::assertNotNull($note);
        self::assertStringContainsString('HTTP 503', $note);
        self::assertStringContainsString('2 min ago', $note);

        self::assertNull(ConnectorService::probeNote(null));
        self::assertNull(ConnectorService::probeNote(['probe_seen_ago' => 120, 'probe_status' => 200]), 'A check WordPress answered normally says nothing about the error.');
        self::assertNull(ConnectorService::probeNote(['probe_seen_ago' => 3600, 'probe_status' => 500]), 'Too old to explain this failure.');
        self::assertNull(ConnectorService::probeNote(['probe_seen_ago' => null, 'probe_status' => null]));
        self::assertNull(ConnectorService::probeNote(['maintenance' => true, 'probe_seen_ago' => 10, 'probe_status' => 503]));
    }
}
