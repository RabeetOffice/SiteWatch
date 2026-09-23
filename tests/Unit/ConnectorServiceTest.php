<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConnectorService;
use PHPUnit\Framework\TestCase;

final class ConnectorServiceTest extends TestCase
{
    public function testConnectionKeyRoundTripsAndMatchesThePluginFormat(): void
    {
        $secret = str_repeat('ab', 32);
        $key = ConnectorService::encodeKey('https://monitor.example.com', 12, $secret);

        self::assertStringStartsWith('swc1_', $key);
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', substr($key, 5), 'The key uses base64url without padding so it survives copy and paste.');
        self::assertSame(['u' => 'https://monitor.example.com', 'i' => 12, 's' => $secret], ConnectorService::decodeKey("  {$key}\n"));
        self::assertNull(ConnectorService::decodeKey('swc1_not-base64!'));
        self::assertNull(ConnectorService::decodeKey('something-else'));
    }

    public function testSignatureCoversTimestampAndBody(): void
    {
        $secret = str_repeat('0f', 32);
        $signature = ConnectorService::sign($secret, '1700000000', '{"v":1}');

        self::assertSame(hash_hmac('sha256', '1700000000.{"v":1}', $secret), $signature);
        self::assertNotSame($signature, ConnectorService::sign($secret, '1700000001', '{"v":1}'));
        self::assertNotSame($signature, ConnectorService::sign($secret, '1700000000', '{"v":2}'));
    }

    public function testNormaliseEventValidatesAndBoundsInput(): void
    {
        $now = time();
        $event = ConnectorService::normaliseEvent([
            'uid'      => 'abcdef0123456789-3',
            'type'     => 'fatal_error',
            'severity' => 'critical',
            'title'    => 'Elementor Pro: Fatal error',
            'at'       => $now,
            'data'     => ['fingerprint' => str_repeat('a', 40), 'new_count' => 3, 'first_at' => $now - 60, 'message' => 'Call to undefined function x()'],
        ]);
        self::assertNotNull($event);
        self::assertSame(str_repeat('a', 40), $event['fingerprint']);
        self::assertSame(3, $event['new_count']);
        self::assertSame(gmdate('Y-m-d H:i:s', $now - 60), $event['occurred_at']);

        // Unknown severity becomes info, future timestamps are clamped, non-fatal events have no fingerprint.
        $other = ConnectorService::normaliseEvent(['uid' => '11111111-2222', 'type' => 'plugin_updated', 'severity' => 'panic', 'at' => $now + 86400]);
        self::assertSame('info', $other['severity']);
        self::assertSame(gmdate('Y-m-d H:i:s', $now), $other['last_occurred_at']);
        self::assertNull($other['fingerprint']);
        self::assertSame('Plugin updated', $other['title']);

        self::assertNull(ConnectorService::normaliseEvent(['uid' => 'short', 'type' => 'plugin_updated']));
        self::assertNull(ConnectorService::normaliseEvent(['uid' => 'abcdefgh1234', 'type' => 'DROP TABLE']));
    }

    public function testSummariseCountsUpdatesAndOpenSecurityChecks(): void
    {
        $summary = ConnectorService::summarise([
            'updates'  => ['core' => ['current' => '6.5', 'latest' => '6.6'], 'plugins' => [['name' => 'A'], ['name' => 'B']], 'themes' => []],
            'security' => [['status' => 'ok'], ['status' => 'warning'], ['status' => 'critical'], ['status' => 'info']],
        ]);
        self::assertSame(['updates' => 3, 'issues' => 2], $summary);
    }

    public function testStateFollowsTheLastReport(): void
    {
        self::assertSame('none', ConnectorService::state(null));
        self::assertSame('pending', ConnectorService::state(['key_created_at' => '2026-09-23 10:00:00', 'connected_at' => null]));
        $recent = gmdate('Y-m-d H:i:s', time() - 60);
        $old = gmdate('Y-m-d H:i:s', time() - ConnectorService::STALE_AFTER - 60);
        self::assertSame('connected', ConnectorService::state(['key_created_at' => $recent, 'connected_at' => $recent, 'last_seen_at' => $recent, 'last_reason' => 'heartbeat']));
        self::assertSame('stale', ConnectorService::state(['key_created_at' => $old, 'connected_at' => $old, 'last_seen_at' => $old, 'last_reason' => 'heartbeat']));
        self::assertSame('deactivated', ConnectorService::state(['key_created_at' => $recent, 'connected_at' => $recent, 'last_seen_at' => $recent, 'last_reason' => 'deactivated']));
        // Website list rows carry the same columns with a connector_ prefix.
        self::assertSame('connected', ConnectorService::state(['connector_key_created_at' => $recent, 'connector_connected_at' => $recent, 'connector_seen_at' => $recent, 'connector_reason' => 'heartbeat']));
    }

    public function testDescribeErrorNamesTheSource(): void
    {
        $line = ConnectorService::describeError(['title' => 'x', 'data' => json_encode([
            'component'  => ['name' => 'Elementor Pro', 'version' => '3.21.0'],
            'error_type' => 'Fatal error',
            'message'    => 'Uncaught Error: Call to undefined function foo()',
            'file'       => 'wp-content/plugins/elementor-pro/modules/forms.php',
            'line'       => 142,
        ])]);
        self::assertSame('Elementor Pro 3.21.0: Fatal error: Uncaught Error: Call to undefined function foo() (wp-content/plugins/elementor-pro/modules/forms.php:142)', $line);
    }
}
