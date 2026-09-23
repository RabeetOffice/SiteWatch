<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use App\Core\Release;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the version, release notes, CHANGELOG.md and database schema steps consistent, so a release cannot ship
 * with a bumped version but a missing note or migration.
 */
final class ReleaseTest extends TestCase
{
    public function testCurrentVersionIsTheNewestReleaseNote(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Release::VERSION);
        self::assertSame(Release::VERSION, array_key_first(Release::NOTES));
    }

    public function testReleaseNotesAreNewestFirstWithIncreasingSchema(): void
    {
        $versions = array_keys(Release::NOTES);
        $sorted = $versions;
        usort($sorted, static fn (string $a, string $b): int => version_compare($b, $a));
        self::assertSame($sorted, $versions);

        $lastSchema = PHP_INT_MAX;
        foreach (Release::NOTES as $version => $notes) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $notes['date'], $version);
            self::assertNotSame('', $notes['title'], $version);
            if ($notes['schema'] !== null) {
                self::assertLessThan($lastSchema, $notes['schema'], "Schema numbers must decrease going back from {$version}.");
                $lastSchema = $notes['schema'];
            }
        }
    }

    public function testDatabaseSchemaMatchesTheRelease(): void
    {
        self::assertSame(Migrator::VERSION, Release::requiredSchema(), 'Migrator::VERSION must equal the schema of the newest release that has one.');
        foreach (Migrator::STEPS as $schema => $step) {
            self::assertArrayHasKey($step['release'], Release::NOTES, "Migrator step {$schema} names an unknown release.");
            self::assertSame($schema, Release::NOTES[$step['release']]['schema'], "Release {$step['release']} must list schema {$schema}.");
            self::assertSame($step['release'], Migrator::releaseFor($schema));
            self::assertSame($step['release'], Release::forSchema($schema));
        }
    }

    public function testChangelogHasEveryRelease(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        foreach (Release::NOTES as $version => $notes) {
            self::assertStringContainsString("## [{$version}] - {$notes['date']}", $changelog, "CHANGELOG.md is missing {$version}.");
        }
    }
}
