<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Release registry: the single source of truth for the SiteWatch version and its release notes.
 *
 * Shipping a release:
 *   1. Add an entry at the top of NOTES and set VERSION to it (semantic versioning: MAJOR.MINOR.PATCH).
 *   2. If the release changes the database, add a Migrator step whose 'release' is this version and put the
 *      schema number in the entry's 'schema' field.
 *   3. Add the same notes to CHANGELOG.md (tests/ReleaseTest.php checks that both agree).
 *
 * The Updates page (System → Updates) shows these notes, and the database version is reported as the release
 * whose schema is applied, so code and database versions can be compared at a glance.
 */
final class Release
{
    public const VERSION = '1.5.0';

    /**
     * Newest first. 'schema' is the database schema version the release requires (null: unchanged).
     *
     * @var array<string, array{date: string, title: string, schema: ?int, added?: array<int, string>, improved?: array<int, string>, fixed?: array<int, string>}>
     */
    public const NOTES = [
        '1.5.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Profile pictures',
            'schema'   => 5,
            'added'    => [
                'Profile picture upload on the Profile page, with a square crop you can drag and zoom before saving.',
                'Profile pictures in the top bar, the sidebar and the Users list; initials are shown when there is no picture.',
            ],
            'improved' => [
                'Uploaded pictures are re-encoded on the server as 256 × 256 WebP images and kept outside the web root, so only signed-in users can load them.',
            ],
        ],
        '1.4.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Fast bulk checks, activity log retention and release tracking',
            'schema'   => 4,
            'added'    => [
                'Live progress panel for "Check now" on many websites: progress bar, estimated time, results as they arrive and a Stop button.',
                '"Select all" can extend the selection to every website that matches the current filters, not only the visible page.',
                'Activity log retention of 7, 15 or 30 days, or unlimited. Old entries are removed by the daily cleanup and straight after the setting is lowered.',
                'Release notes and code/database version comparison on System → Updates; the version is shown in the sidebar.',
            ],
            'improved' => [
                'Bulk checks run in parallel batches, so fast websites no longer wait for slow ones and large selections finish several times sooner.',
                'A website that fails once is re-checked after one minute, so a real outage is confirmed and alerted within minutes.',
                'Browsers load new JavaScript and CSS right after a deploy (asset URLs include the file modification time).',
            ],
            'fixed'    => [
                '"MySQL server has gone away" errors on the host broke Check now, cron runs and alerts; the connection now reconnects automatically.',
                'Many simultaneous checks queued behind one session lock and overloaded the server.',
                'A down alert that failed to send is retried for up to an hour instead of being lost.',
            ],
        ],
        '1.3.0' => [
            'date'     => '2026-09-21',
            'title'    => 'Core Web Vitals, screenshots and more alert channels',
            'schema'   => 3,
            'added'    => [
                'Core Web Vitals history (lab and field data, mobile and desktop) through PageSpeed Insights.',
                'Website screenshots.',
                'Time to first byte recorded on every check.',
                'WhatsApp and Discord notification channels.',
            ],
            'fixed'    => [
                'Website search and filter fixes.',
            ],
        ],
        '1.2.0' => [
            'date'     => '2026-09-15',
            'title'    => 'Team roles, domains & hosting, database updates',
            'schema'   => 2,
            'added'    => [
                'Users with Administrator, Manager and Viewer roles, and custom roles with permissions.',
                'Domain registration (WHOIS / RDAP) and hosting provider details.',
                'System → Updates page for applying database updates after a deploy.',
            ],
        ],
        '1.0.0' => [
            'date'     => '2026-09-14',
            'title'    => 'First release',
            'schema'   => 1,
            'added'    => [
                'Uptime, response time, SSL and WordPress error monitoring with confirmed incidents.',
                'Email and Telegram alerts, uptime and response time reports, activity log.',
            ],
        ],
    ];

    /**
     * The release that introduced database schema $schema (the newest release using it).
     */
    public static function forSchema(int $schema): ?string
    {
        $match = null;
        foreach (self::NOTES as $version => $notes) {
            if ($notes['schema'] === $schema) {
                $match ??= $version;
            }
        }
        return $match;
    }

    /**
     * The database schema version the current code requires.
     */
    public static function requiredSchema(): int
    {
        foreach (self::NOTES as $notes) {
            if ($notes['schema'] !== null) {
                return $notes['schema'];
            }
        }
        return 1;
    }
}
