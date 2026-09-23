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
    public const VERSION = '1.14.0';

    /**
     * Newest first. 'schema' is the database schema version the release requires (null: unchanged).
     *
     * @var array<string, array{date: string, title: string, schema: ?int, added?: array<int, string>, improved?: array<int, string>, fixed?: array<int, string>}>
     */
    public const NOTES = [
        '1.14.0' => [
            'date'     => '2026-09-23',
            'title'    => 'BackWPup backups, earlier reports, background updates, new plugin page',
            'schema'   => null,
            'added'    => [
                'SiteWatch Connector 1.8.0 has its own entry in the WordPress admin menu, with the SiteWatch mark, and a redesigned page in the SiteWatch look: status tiles, switches for remote actions and auto-fix, and the connection details at a glance. Links to the old Settings → SiteWatch page are redirected.',
                'Backups with BackWPup: the backup remote action (single site and bulk) also starts BackWPup\'s first backup job when BackWPup is installed, and the Remote actions tab shows the last run of each backup plugin (UpdraftPlus and BackWPup).',
                'Earlier daily reports on the Performance tab: choose any stored report of the last 90 days to see its page speed, slowest requests and slow database queries.',
            ],
            'improved' => [
                'Plugin, theme and WordPress updates and rollbacks requested from SiteWatch run in a background request of their own on the site (SiteWatch Connector 1.8.0), one at a time with a longer time limit, so large updates on slow hosts no longer time out inside the 5-minute report.',
            ],
        ],
        '1.13.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Performance history, theme and core updates, backups',
            'schema'   => 12,
            'added'    => [
                'History of page speed and PHP warnings: every daily report from the WordPress plugin is kept for 90 days. The Performance tab shows the median and 95% page generation time over time, and the PHP warnings section lists earlier reports.',
                'Remote actions for SiteWatch Connector 1.7.0: install theme updates, install the WordPress update WordPress.org offers (with WordPress\'s own rollback on failure), and start an UpdraftPlus backup. Theme updates and backups also work in bulk from the website list; WordPress core updates are per site on purpose.',
                'The Remote actions tab shows UpdraftPlus\'s last backup, from the health report.',
            ],
        ],
        '1.12.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Private plugin data on every server, compressed reports',
            'schema'   => null,
            'improved' => [
                'SiteWatch Connector 1.6.0 keeps its data files (captured errors, warnings, page timings, auto-fix flags) private on every web server: they are now PHP files that print nothing when requested, where the folder\'s .htaccess only protected Apache and LiteSpeed. Existing files are converted with the next report.',
                'Large reports from sites with long plugin lists are sent gzip-compressed; SiteWatch refuses anything that expands beyond the 2 MB report limit.',
                'When an outage is confirmed and WordPress itself answered SiteWatch\'s check with a 5xx, the incident says so: the error comes from the site (PHP, database or a plugin), not from the network or a firewall.',
            ],
            'fixed'    => [
                'Plugin self-updates (and rollbacks) failed with "Could not access filesystem" when the WordPress list of available updates was missing, for example right after another update; the update then waited 6 hours to retry. Fixed in plugin 1.6.0 (sites on older versions still retry by themselves).',
            ],
        ],
        '1.11.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Auto-fix for WordPress plugins',
            'schema'   => 11,
            'added'    => [
                'Auto-fix in SiteWatch Connector 1.5.0: when the same fatal error from one plugin happens 3 times within 10 minutes, the plugin is deactivated before the next page loads, so visitors get a working site, and SiteWatch alerts you ("WordPress fatal error" rule). Off until a WordPress administrator switches it on; protected plugins are never touched, and a plugin is switched off automatically at most once a day.',
                'Roll back a plugin update: the plugin records the version each plugin had before its last update, and the new "Roll back plugin" remote action installs exactly that version from WordPress.org.',
                'Fatal errors from a plugin on the Errors tab offer "Deactivate" and, after a recent update, "Roll back to x"; an automatically deactivated plugin shows "Activate again".',
            ],
        ],
        '1.10.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Remote actions for WordPress sites',
            'schema'   => 10,
            'added'    => [
                'Remote actions for WordPress sites with SiteWatch Connector 1.4.0: clear caches, deactivate or activate a plugin, install plugin updates from WordPress.org, and a maintenance page for 5 minutes to 24 hours, from the new "Remote actions" tab on the website page. Each action must first be allowed by the site\'s WordPress administrator under Settings → SiteWatch, and requests are signed with the site\'s secret.',
                'Bulk remote actions: select websites in the website list and choose WordPress… → Clear caches, Install plugin updates (each site installs the updates in its own last health report) or Maintenance page on/off. Sites that are not connected, run an older plugin or do not allow the action are skipped and named.',
                'New permission "Run remote actions" (Administrators have it; give it to other roles under Team → Roles). Every request and its result is logged.',
                'While a maintenance page switched on from SiteWatch is showing, maintenance and 503 alerts for that site are held.',
            ],
        ],
        '1.9.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Page speed and PHP warnings from inside WordPress',
            'schema'   => 9,
            'added'    => [
                'Page speed from inside WordPress: SiteWatch Connector 1.3.0 times 1 in 20 requests (adjustable, or off, under Monitoring Settings → WordPress plugin) and reports page generation time percentiles, database query counts, memory and the slowest pages daily. With SAVEQUERIES on, queries slower than 50 ms are listed with their values replaced by "?". See the new Performance tab.',
                'Optional PHP warning summary: switch on "Collect PHP warnings and deprecations" to see warnings, notices and deprecation notices per file and line, with the plugin or theme responsible, on the Errors tab. The plugin only counts them; logging and display are unchanged.',
                'A note on the website page when SiteWatch\'s checks have not reached WordPress for two hours while visitors are served: a page cache or CDN (harmless while checks pass) or, when checks fail, a firewall blocking SiteWatch, with the User-Agent and addresses to allow-list.',
            ],
        ],
        '1.8.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Known vulnerabilities and protected file changes',
            'schema'   => 8,
            'added'    => [
                'Known vulnerabilities: SiteWatch checks the plugins, themes and WordPress version reported by each connected site against the free WPVulnerability database, twice a day and after every health report. The Security tab lists each issue with its severity, CVE and the version that fixes it, and vulnerable plugins are flagged on the Plugins & themes tab.',
                'New alert rule "Known vulnerabilities (plugin)": one alert per site when new vulnerabilities are found. Only plugin, theme and version names are looked up, never the site.',
                'SiteWatch Connector 1.2.0 watches wp-config.php and .htaccess. A change made outside WordPress (FTP, hosting panel, malware) sends a security alert; changes WordPress makes itself, such as saving permalinks or activating a caching plugin, are logged in the Activity tab. The file contents never leave the site.',
            ],
        ],
        '1.7.0' => [
            'date'     => '2026-09-23',
            'title'    => 'Plugin self-update and fewer false outage alerts',
            'schema'   => 7,
            'added'    => [
                'SiteWatch Connector 1.1.0 updates itself from your SiteWatch server through WordPress\'s own updater, which restores the previous version if an update breaks the site (WordPress 6.6+). Turn automatic updates on or off under Monitoring Settings, or use "Update plugin" on a website.',
                'Inside view on the website page: the last page WordPress served, the last server error, and the last SiteWatch check that reached WordPress.',
            ],
            'improved' => [
                'Fewer false outage alerts: when checks fail with a 5xx, timeout or connection error but the plugin reports WordPress is serving pages normally, the alert is held for up to 30 minutes and then sent with a note that SiteWatch is probably being blocked.',
            ],
        ],
        '1.6.0' => [
            'date'     => '2026-09-23',
            'title'    => 'SiteWatch Connector for WordPress',
            'schema'   => 6,
            'added'    => [
                'SiteWatch Connector, a WordPress plugin downloadable from each website page. It reports fatal errors with the file, line and plugin or theme responsible, without turning on debug.',
                'Down alerts include the cause when the plugin reported a fatal error, e.g. "Elementor Pro: Call to undefined function … line 142".',
                'Daily health report per site: WordPress/PHP/database versions, pending updates, plugins and themes, database size and scheduled tasks.',
                'Security checks: modified WordPress core files, PHP files in uploads, risky registration settings, the "admin" username and more. New administrator accounts and site address changes are alerted immediately.',
                'WordPress change log: plugin and theme installs, updates and switches, WordPress updates, administrator sign-ins and failed sign-in counts.',
                'Websites with the plugin are marked in the website list, with a filter, and the dashboard shows how many sites are connected.',
            ],
        ],
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
