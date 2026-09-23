# Changelog

All notable changes to SiteWatch are recorded here. Versions follow [Semantic Versioning](https://semver.org/):
MAJOR for breaking changes, MINOR for new features, PATCH for fixes.

The in-app copy of these notes (System → Updates) comes from `app/Core/Release.php`. When you ship a release, update
both files. `tests/ReleaseTest.php` fails if they disagree. A release that changes the database also gets a
`Migrator` step. Its schema number is listed below, so the code and database versions can be compared.

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
