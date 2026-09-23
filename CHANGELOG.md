# Changelog

All notable changes to SiteWatch are recorded here. Versions follow [Semantic Versioning](https://semver.org/):
MAJOR for breaking changes, MINOR for new features, PATCH for fixes.

The in-app copy of these notes (System → Updates) comes from `app/Core/Release.php`. When you ship a release, update
both files. `tests/ReleaseTest.php` fails if they disagree. A release that changes the database also gets a
`Migrator` step. Its schema number is listed below, so the code and database versions can be compared.

## [1.12.0] - 2026-09-23

Private plugin data on every server, compressed reports.

### Improved
- SiteWatch Connector 1.6.0 keeps its data files (captured errors, warnings, page timings, auto-fix flags) private on every web server: they are now PHP files that print nothing when requested, where the folder's .htaccess only protected Apache and LiteSpeed. Existing files are converted with the next report.
- Large reports from sites with long plugin lists are sent gzip-compressed; SiteWatch refuses anything that expands beyond the 2 MB report limit.
- When an outage is confirmed and WordPress itself answered SiteWatch's check with a 5xx, the incident says so: the error comes from the site (PHP, database or a plugin), not from the network or a firewall.

### Fixed
- Plugin self-updates (and rollbacks) failed with "Could not access filesystem" when the WordPress list of available updates was missing, for example right after another update; the update then waited 6 hours to retry. Fixed in plugin 1.6.0 (sites on older versions still retry by themselves).

### Upgrading
- No database update. Connected sites install plugin 1.6.0 by themselves.

## [1.11.0] - 2026-09-23 · database schema 11

Auto-fix for WordPress plugins.

### Added
- Auto-fix in SiteWatch Connector 1.5.0: when the same fatal error from one plugin happens 3 times within 10 minutes, the plugin is deactivated before the next page loads, so visitors get a working site, and SiteWatch alerts you ("WordPress fatal error" rule). Off until a WordPress administrator switches it on; protected plugins are never touched, and a plugin is switched off automatically at most once a day.
- Roll back a plugin update: the plugin records the version each plugin had before its last update, and the new "Roll back plugin" remote action installs exactly that version from WordPress.org.
- Fatal errors from a plugin on the Errors tab offer "Deactivate" and, after a recent update, "Roll back to x"; an automatically deactivated plugin shows "Activate again".

### Upgrading
- Apply the database update under System → Updates. Sites install plugin 1.5.0 by themselves; auto-fix and the rollback action stay off until a WordPress administrator switches them on.

### Database (schema 11)
- New `connector_sites` column `autofix`.

## [1.10.0] - 2026-09-23 · database schema 10

Remote actions for WordPress sites.

### Added
- Remote actions for WordPress sites with SiteWatch Connector 1.4.0: clear caches, deactivate or activate a plugin, install plugin updates from WordPress.org, and a maintenance page for 5 minutes to 24 hours, from the new "Remote actions" tab on the website page. Each action must first be allowed by the site's WordPress administrator under Settings → SiteWatch, and requests are signed with the site's secret.
- Bulk remote actions: select websites in the website list and choose WordPress… → Clear caches, Install plugin updates (each site installs the updates in its own last health report) or Maintenance page on/off. Sites that are not connected, run an older plugin or do not allow the action are skipped and named.
- New permission "Run remote actions" (Administrators have it; give it to other roles under Team → Roles). Every request and its result is logged.
- While a maintenance page switched on from SiteWatch is showing, maintenance and 503 alerts for that site are held.

### Upgrading
- Apply the database update under System → Updates. Sites install plugin 1.4.0 by themselves; remote actions stay off until a WordPress administrator allows them.

### Database (schema 10)
- New table `connector_commands`; new `connector_sites` columns `remote_actions` and `maintenance_until`.

## [1.9.0] - 2026-09-23 · database schema 9

Page speed and PHP warnings from inside WordPress.

### Added
- Page speed from inside WordPress: SiteWatch Connector 1.3.0 times 1 in 20 requests (adjustable, or off, under Monitoring Settings → WordPress plugin) and reports page generation time percentiles, database query counts, memory and the slowest pages daily. With SAVEQUERIES on, queries slower than 50 ms are listed with their values replaced by "?". See the new Performance tab.
- Optional PHP warning summary: switch on "Collect PHP warnings and deprecations" to see warnings, notices and deprecation notices per file and line, with the plugin or theme responsible, on the Errors tab. The plugin only counts them; logging and display are unchanged.
- A note on the website page when SiteWatch's checks have not reached WordPress for two hours while visitors are served: a page cache or CDN (harmless while checks pass) or, when checks fail, a firewall blocking SiteWatch, with the User-Agent and addresses to allow-list.

### Upgrading
- Apply the database update under System → Updates. Connected sites install plugin 1.3.0 by themselves when automatic plugin updates are on.

### Database (schema 9)
- New `connector_sites` column `probe_ip`.

## [1.8.0] - 2026-09-23 · database schema 8

Known vulnerabilities and protected file changes.

### Added
- Known vulnerabilities: SiteWatch checks the plugins, themes and WordPress version reported by each connected site against the free WPVulnerability database, twice a day and after every health report. The Security tab lists each issue with its severity, CVE and the version that fixes it, and vulnerable plugins are flagged on the Plugins & themes tab.
- New alert rule "Known vulnerabilities (plugin)": one alert per site when new vulnerabilities are found. Only plugin, theme and version names are looked up, never the site.
- SiteWatch Connector 1.2.0 watches wp-config.php and .htaccess. A change made outside WordPress (FTP, hosting panel, malware) sends a security alert; changes WordPress makes itself, such as saving permalinks or activating a caching plugin, are logged in the Activity tab. The file contents never leave the site.

### Upgrading
- Apply the database update under System → Updates. Connected sites on plugin 1.1.0 install 1.2.0 by themselves when automatic plugin updates are on.
- The monitoring cron (`cron/monitor.php`) does the vulnerability lookups, a few per run; no new cron job is needed. The server must be able to reach `https://www.wpvulnerability.net`.

### Database (schema 8)
- New table `vulnerability_feed` (shared cache of lookups, entries unused for 7 days are removed by the daily cleanup).
- New `connector_sites` columns: `vuln_count`, `vuln_report`, `vuln_checked_at`, `vuln_incomplete`.

## [1.7.0] - 2026-09-23 · database schema 7

Plugin self-update and fewer false outage alerts.

### Added
- SiteWatch Connector 1.1.0 updates itself from your SiteWatch server through WordPress's own updater, which restores the previous version if an update breaks the site (WordPress 6.6+). Turn automatic updates on or off under Monitoring Settings, or use "Update plugin" on a website.
- Inside view on the website page: the last page WordPress served, the last server error, and the last SiteWatch check that reached WordPress.

### Improved
- Fewer false outage alerts: when checks fail with a 5xx, timeout or connection error but the plugin reports WordPress is serving pages normally, the alert is held for up to 30 minutes and then sent with a note that SiteWatch is probably being blocked.

### Upgrading
- Sites running plugin 1.0.0 need one manual update to 1.1.0 (Download plugin, then Plugins → Add New → Upload → "Replace current with uploaded"). From 1.1.0 on, the plugin updates itself.

### Database (schema 7)
- New `connector_sites` columns: `pulse_ok_at`, `pulse_error_at`, `probe_seen_at`, `probe_status`, `want_update`, `update_result`, `update_at`.

## [1.6.0] - 2026-09-23 · database schema 6

SiteWatch Connector for WordPress.

### Added
- SiteWatch Connector, a WordPress plugin you download from each website page. It reports fatal errors with the file, line and plugin or theme responsible, without turning on debug.
- Down alerts include the cause when the plugin reported a fatal error, e.g. "Elementor Pro: Call to undefined function … line 142".
- Daily health report per site: WordPress/PHP/database versions, pending updates, plugins and themes, database size and scheduled tasks.
- Security checks: modified WordPress core files, PHP files in uploads, risky registration settings, the "admin" username and more. New administrator accounts and site address changes are alerted immediately.
- WordPress change log: plugin and theme installs, updates and switches, WordPress updates, administrator sign-ins and failed sign-in counts.
- Websites with the plugin are marked in the website list, with a filter, and the dashboard shows how many sites are connected.

### Database (schema 6)
- New tables `connector_sites` and `connector_events`.

## [1.5.0] - 2026-09-23 · database schema 5

Profile pictures.

### Added
- Profile picture upload on the Profile page, with a square crop you can drag and zoom before saving.
- Profile pictures in the top bar, the sidebar and the Users list; initials are shown when there is no picture.

### Improved
- Uploaded pictures are re-encoded on the server as 256 × 256 WebP images and kept outside the web root, so only signed-in users can load them.

### Database (schema 5)
- New column `users.avatar`.

## [1.4.0] - 2026-09-23 · database schema 4

Fast bulk checks, activity log retention and release tracking.

### Added
- Live progress panel for "Check now" on many websites: progress bar, estimated time, results as they arrive and a Stop button.
- "Select all" can extend the selection to every website that matches the current filters, not only the visible page.
- Activity log retention of 7, 15 or 30 days, or unlimited. The daily cleanup removes old entries, and they are also removed straight away when the setting is lowered.
- Release notes and code/database version comparison on System → Updates; the version is shown in the sidebar.

### Improved
- Bulk checks run in parallel batches, so fast websites no longer wait for slow ones and large selections finish several times sooner.
- A website that fails once is re-checked after one minute, so a real outage is confirmed and alerted within minutes.
- Browsers load new JavaScript and CSS right after a deploy (asset URLs include the file modification time).

### Fixed
- "MySQL server has gone away" errors on the host broke Check now, cron runs and alerts; the connection now reconnects automatically.
- Many simultaneous checks queued behind one session lock and overloaded the server.
- A down alert that failed to send is retried for up to an hour instead of being lost.

### Database (schema 4)
- An `activity_retention_days` value above 30 becomes 30.
- New index `website_checks.idx_checks_website_failure`.

## [1.3.0] - 2026-09-21 · database schema 3

Core Web Vitals, screenshots and more alert channels.

### Added
- Core Web Vitals history (lab and field data, mobile and desktop) through PageSpeed Insights.
- Website screenshots.
- Time to first byte recorded on every check.
- WhatsApp and Discord notification channels.

### Fixed
- Website search and filter fixes.

## [1.2.0] - 2026-09-15 · database schema 2

Team roles, domains & hosting, database updates.

### Added
- Users with Administrator, Manager and Viewer roles, and custom roles with permissions.
- Domain registration (WHOIS / RDAP) and hosting provider details.
- System → Updates page for applying database updates after a deploy.

## [1.0.0] - 2026-09-14 · database schema 1

First release.

### Added
- Uptime, response time, SSL and WordPress error monitoring with confirmed incidents.
- Email and Telegram alerts, uptime and response time reports, activity log.
