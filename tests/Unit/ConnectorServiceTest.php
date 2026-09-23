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

    public function testInsideEvidenceOnlyHoldsWhenWordPressIsCleanlyServingPages(): void
    {
        $now = 1_800_000_000;
        $at = static fn (int $ago): string => gmdate('Y-m-d H:i:s', $now - $ago);
        $base = ['connected_at' => $at(86400), 'last_seen_at' => $at(120), 'last_reason' => 'heartbeat', 'pulse_ok_at' => $at(90), 'pulse_error_at' => null, 'probe_seen_at' => $at(3600), 'probe_status' => 200];

        $healthy = ConnectorService::judgeEvidence($base, $now, false);
        self::assertTrue($healthy['healthy']);
        self::assertSame(90, $healthy['ok_ago']);
        self::assertSame(3600, $healthy['probe_seen_ago'], 'SiteWatch checks have not reached WordPress for an hour: they are being blocked.');

        self::assertFalse(ConnectorService::judgeEvidence(['pulse_error_at' => $at(300)] + $base, $now, false)['healthy'], 'A recent 5xx inside WordPress means the outage is real.');
        self::assertFalse(ConnectorService::judgeEvidence($base, $now, true)['healthy'], 'A recent fatal error means the outage is real.');
        self::assertFalse(ConnectorService::judgeEvidence(['pulse_ok_at' => $at(3600)] + $base, $now, false)['healthy'], 'No page served recently.');
        self::assertTrue(ConnectorService::judgeEvidence(['pulse_error_at' => $at(7200)] + $base, $now, false)['healthy'], 'Old errors do not count.');

        self::assertNull(ConnectorService::judgeEvidence(['last_seen_at' => $at(3600)] + $base, $now, false), 'A plugin that stopped reporting is no evidence at all.');
        self::assertNull(ConnectorService::judgeEvidence(['last_reason' => 'deactivated'] + $base, $now, false));
    }

    public function testPackageSignatureIsBoundToSiteVersionAndExpiry(): void
    {
        $secret = str_repeat('cd', 32);
        $sig = ConnectorService::packageSignature($secret, 12, '1.0.1', 1_800_000_000);
        self::assertSame(64, strlen($sig));
        self::assertNotSame($sig, ConnectorService::packageSignature($secret, 13, '1.0.1', 1_800_000_000));
        self::assertNotSame($sig, ConnectorService::packageSignature($secret, 12, '1.0.2', 1_800_000_000));
        self::assertNotSame($sig, ConnectorService::packageSignature($secret, 12, '1.0.1', 1_800_000_001));
        self::assertNotSame($sig, ConnectorService::packageSignature(str_repeat('ce', 32), 12, '1.0.1', 1_800_000_000));
    }

    public function testCheckResultWithNoteAppendsToTheMessage(): void
    {
        $result = new \App\Monitoring\CheckResult('HTTP_503', true, 503, 120, null, null, 'Server returned HTTP 503.');
        self::assertSame('Server returned HTTP 503. Alert held.', $result->withNote('Alert held.')->errorMessage);
        self::assertSame('Alert held.', (new \App\Monitoring\CheckResult('TIMEOUT', true))->withNote('Alert held.')->errorMessage);
        self::assertSame('HTTP_503', $result->withNote('x')->status);
    }
}
