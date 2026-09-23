<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Monitoring\Status;
use App\Services\ConnectorService;
use PHPUnit\Framework\TestCase;

final class ConnectorReachabilityTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private static function at(int $secondsAgo): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW - $secondsAgo);
    }

    /** A connected site whose WordPress serves pages and whose checks run, but no check reached WordPress for 3 h. */
    private static function row(array $override = []): array
    {
        return $override + [
            'connected_at'  => self::at(86400),
            'last_seen_at'  => self::at(120),
            'last_reason'   => 'heartbeat',
            'pulse_ok_at'   => self::at(60),
            'probe_seen_at' => self::at(3 * 3600),
            'probe_ip'      => '203.0.113.9',
        ];
    }

    private static function website(string $status, array $override = []): array
    {
        return $override + ['monitoring_enabled' => 1, 'status' => $status, 'last_checked_at' => self::at(90)];
    }

    public function testChecksAnsweredBeforeWordPressWhileTheyPass(): void
    {
        $result = ConnectorService::reachability(self::row(), self::website(Status::ONLINE), self::NOW, 'SiteWatch/1.0');
        self::assertNotNull($result);
        self::assertSame('cached', $result['state']);
        self::assertFalse($result['checks_failing']);
        self::assertSame(3 * 3600, $result['probe_ago']);
        self::assertSame('203.0.113.9', $result['probe_ip']);
        self::assertSame('SiteWatch/1.0', $result['user_agent']);
    }

    public function testFailingChecksWithWordPressServingPagesMeansBlocked(): void
    {
        foreach ([Status::DOWN, Status::TIMEOUT, Status::HTTP_503, Status::SUSPECTED_DOWN] as $status) {
            self::assertSame('blocked', ConnectorService::reachability(self::row(), self::website($status), self::NOW)['state'] ?? null, $status);
        }
    }

    public function testNothingToSayWhenChecksReachWordPressOrThereIsNoBasis(): void
    {
        $online = self::website(Status::ONLINE);
        self::assertNull(ConnectorService::reachability(self::row(['probe_seen_at' => self::at(600)]), $online, self::NOW), 'A recent check reached WordPress.');
        self::assertNull(ConnectorService::reachability(self::row(['pulse_ok_at' => self::at(3600)]), $online, self::NOW), 'WordPress itself has served nothing lately.');
        self::assertNull(ConnectorService::reachability(self::row(['last_seen_at' => self::at(7200)]), $online, self::NOW), 'The plugin stopped reporting.');
        self::assertNull(ConnectorService::reachability(self::row(['last_reason' => 'deactivated']), $online, self::NOW));
        self::assertNull(ConnectorService::reachability(self::row(), self::website(Status::ONLINE, ['monitoring_enabled' => 0]), self::NOW), 'Monitoring is paused.');
        self::assertNull(ConnectorService::reachability(self::row(), self::website(Status::ONLINE, ['last_checked_at' => self::at(7200)]), self::NOW), 'Checks are not running.');
        self::assertNull(ConnectorService::reachability(self::row(), null, self::NOW));
    }

    public function testNewlyConnectedSiteGetsTimeToSeeACheck(): void
    {
        $online = self::website(Status::ONLINE);
        self::assertNull(ConnectorService::reachability(self::row(['probe_seen_at' => null, 'connected_at' => self::at(1800)]), $online, self::NOW));
        $never = ConnectorService::reachability(self::row(['probe_seen_at' => null, 'connected_at' => self::at(3 * 3600)]), $online, self::NOW);
        self::assertSame('cached', $never['state'] ?? null);
        self::assertNull($never['probe_ago']);
    }
}
