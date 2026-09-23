<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ConnectorService;
use App\Services\RemoteActionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RemoteActionTest extends TestCase
{
    private const SNAPSHOT = [
        'plugins' => [
            ['file' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.0', 'active' => true, 'update' => '5.3'],
            ['file' => 'hello.php', 'name' => 'Hello Dolly', 'version' => '1.7', 'active' => false, 'update' => null],
            ['file' => 'sitewatch-connector/sitewatch-connector.php', 'name' => 'SiteWatch Connector', 'version' => '1.4.0', 'active' => true, 'update' => null],
        ],
    ];

    public function testSignatureMatchesThePluginFormat(): void
    {
        $secret = str_repeat('ab', 32);
        $sig = RemoteActionService::sign($secret, 44, 7, 'clear_cache', '[]', 1_800_000_000);
        self::assertSame(hash_hmac('sha256', 'command|44|7|clear_cache|[]|1800000000', $secret), $sig);
        self::assertNotSame($sig, RemoteActionService::sign($secret, 45, 7, 'clear_cache', '[]', 1_800_000_000), 'Bound to the site.');
        self::assertNotSame($sig, RemoteActionService::sign($secret, 44, 7, 'clear_cache', '{}', 1_800_000_000), 'Bound to the exact arguments.');
    }

    public function testAllowedActionsFromThePluginReport(): void
    {
        self::assertNull(RemoteActionService::allowed(['remote_actions' => null]), 'Older plugins cannot do remote actions.');
        self::assertSame([], RemoteActionService::allowed(['remote_actions' => '[]']));
        self::assertSame(['clear_cache', 'maintenance'], RemoteActionService::allowed(['remote_actions' => '["maintenance","run_php","clear_cache"]']), 'Unknown names are ignored.');
    }

    public function testArgumentsAreCheckedAgainstTheSnapshot(): void
    {
        self::assertSame([], RemoteActionService::validateArgs('clear_cache', ['anything' => 1], self::SNAPSHOT));
        self::assertSame(['plugin' => 'akismet/akismet.php'], RemoteActionService::validateArgs('deactivate_plugin', ['plugin' => 'akismet/akismet.php'], self::SNAPSHOT));
        self::assertSame(['plugin' => 'hello.php'], RemoteActionService::validateArgs('activate_plugin', ['plugin' => 'hello.php'], self::SNAPSHOT));
        self::assertSame(['plugins' => ['akismet/akismet.php']], RemoteActionService::validateArgs('update_plugins', ['plugins' => ['akismet/akismet.php', 'akismet/akismet.php']], self::SNAPSHOT));
        self::assertSame(['mode' => 'on', 'minutes' => 60, 'message' => 'Back at 5 pm'], RemoteActionService::validateArgs('maintenance', ['mode' => 'on', 'minutes' => '60', 'message' => "<b>Back</b>  at\n5 pm"], self::SNAPSHOT));
        self::assertSame(['mode' => 'off'], RemoteActionService::validateArgs('maintenance', ['mode' => 'off', 'minutes' => 9999], self::SNAPSHOT));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function refusedRequests(): array
    {
        return [
            'unknown plugin'          => ['deactivate_plugin', ['plugin' => '../../wp-config.php']],
            'itself'                  => ['deactivate_plugin', ['plugin' => 'sitewatch-connector/sitewatch-connector.php']],
            'already inactive'        => ['deactivate_plugin', ['plugin' => 'hello.php']],
            'already active'          => ['activate_plugin', ['plugin' => 'akismet/akismet.php']],
            'no update available'     => ['update_plugins', ['plugins' => ['hello.php']]],
            'nothing to update'       => ['update_plugins', ['plugins' => []]],
            'update itself'           => ['update_plugins', ['plugins' => ['sitewatch-connector/sitewatch-connector.php']]],
            'maintenance too short'   => ['maintenance', ['mode' => 'on', 'minutes' => 1]],
            'maintenance too long'    => ['maintenance', ['mode' => 'on', 'minutes' => 1441]],
            'not an allowlist action' => ['run_php', ['code' => 'phpinfo();']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedRequests')]
    public function testUnsafeOrPointlessRequestsAreRefused(string $action, array $args): void
    {
        $this->expectException(RuntimeException::class);
        RemoteActionService::validateArgs($action, $args, self::SNAPSHOT);
    }

    public function testRollbackOnlyToTheRecordedPreviousVersion(): void
    {
        $snapshot = ['plugins' => [
            ['file' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.7.2', 'active' => true, 'previous_version' => '5.0', 'updated_at' => 1_800_000_000],
            ['file' => 'hello.php', 'name' => 'Hello Dolly', 'version' => '1.7', 'active' => false],
        ]];
        self::assertSame(['plugin' => 'akismet/akismet.php', 'version' => '5.0'], RemoteActionService::validateArgs('rollback_plugin', ['plugin' => 'akismet/akismet.php', 'version' => '5.0'], $snapshot));
        foreach ([
            ['plugin' => 'akismet/akismet.php', 'version' => '4.0'],        // any other version
            ['plugin' => 'akismet/akismet.php'],                            // no version
            ['plugin' => 'hello.php', 'version' => '1.6'],                  // nothing recorded
            ['plugin' => 'akismet/akismet.php', 'version' => ['5.0']],      // wrong type
        ] as $args) {
            try {
                RemoteActionService::validateArgs('rollback_plugin', $args, $snapshot);
                self::fail('Accepted ' . json_encode($args));
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBulkUpdatesUseEachSitesOwnPendingUpdates(): void
    {
        self::assertSame(['akismet/akismet.php'], RemoteActionService::pendingUpdates(self::SNAPSHOT));
        $withConnectorUpdate = ['plugins' => [['file' => 'sitewatch-connector/sitewatch-connector.php', 'update' => '9.9']]];
        self::assertSame([], RemoteActionService::pendingUpdates($withConnectorUpdate), 'The connector updates itself.');
        $many = ['plugins' => array_map(static fn (int $i): array => ['file' => "p{$i}/p{$i}.php", 'update' => '2.0'], range(1, 30))];
        self::assertCount(20, RemoteActionService::pendingUpdates($many));
        self::assertSame(['clear_cache', 'update_plugins', 'maintenance'], RemoteActionService::BULK_ACTIONS, 'Single-plugin actions are not offered in bulk.');
    }

    public function testPlannedMaintenanceHoldsAlertsUntilShortlyAfterItEnds(): void
    {
        $now = 1_800_000_000;
        $row = ['maintenance_until' => gmdate('Y-m-d H:i:s', $now + 600)];
        $evidence = ConnectorService::plannedMaintenance($row, $now);
        self::assertNotNull($evidence);
        self::assertTrue($evidence['maintenance']);
        self::assertNotNull(ConnectorService::plannedMaintenance($row, $now + 600 + RemoteActionService::MAINTENANCE_GRACE), 'Grace period while the site comes back.');
        self::assertNull(ConnectorService::plannedMaintenance($row, $now + 601 + RemoteActionService::MAINTENANCE_GRACE));
        self::assertNull(ConnectorService::plannedMaintenance(['maintenance_until' => null], $now));
    }
}
