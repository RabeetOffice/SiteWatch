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
