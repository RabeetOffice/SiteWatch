# SiteWatch Connector (WordPress plugin): status and handoff

What the plugin does, how it is built, what has been tested, and what is still to do. Written so that a new
session can continue the work without re-reading the whole history.

| | |
|---|---|
| Plugin version | **1.3.0** (`wordpress-plugin/sitewatch-connector/`) |
| SiteWatch version | **1.9.0**, database schema **9** |
| Last pushed commit | `64a1860 New Capabilities Added in Plugin` (1.8.0 / plugin 1.2.0); 1.9.0 / plugin 1.3.0 not committed yet |
| Requirements | WordPress 5.2+, PHP 7.2+ (plugin); rollback on failed self-update needs WordPress 6.6+ |

---

## 1. What it is

SiteWatch checks websites from the outside, so it can say *that* a site failed but not *why*. The SiteWatch
Connector plugin runs inside WordPress and reports to SiteWatch. It:

- captures **fatal PHP errors with their real cause**: message, file, line, and the plugin or theme responsible
  (with version), without turning on `WP_DEBUG` and without showing anything to visitors;
- sends a **daily health and security report**;
- keeps a **change log** (plugin/theme/core changes, admin sign-ins, new administrators, failed sign-ins);
- **updates itself** from the SiteWatch server;
- **watches `wp-config.php` and `.htaccess`** and reports changes made outside WordPress (fingerprints only);
- measures **page generation time** on a sample of requests and, if switched on, counts **PHP warnings and deprecations**;
- tells SiteWatch whether WordPress is **serving pages**, so outages caused by SiteWatch's checks being blocked do not
  produce false alerts.

SiteWatch itself matches the reported plugins, themes and WordPress version against **known vulnerabilities**
(WPVulnerability database), so the WordPress sites do no extra work for that.

## 2. Architecture

```
WordPress site                                     SiteWatch server
┌──────────────────────────────┐   HTTPS POST      ┌──────────────────────────────────┐
│ SiteWatch Connector plugin    │ ───────────────▶  │ api/connector/ingest.php         │
│  • mu-plugin loader (early)   │  signed (HMAC)    │   ConnectorService::verify/ingest│
│  • error capture              │                   │   → connector_sites (snapshot…)  │
│  • pulse (served pages)       │ ◀───────────────  │   → connector_events (errors…)   │
│  • health snapshot            │  JSON reply:      │   → alerts (NotificationManager) │
│  • activity log               │  want_snapshot,   │                                  │
│  • self-updater               │  plugin_update…   │ api/connector/package.php        │
└──────────────────────────────┘   GET (signed URL) │   plugin zip for self-updates    │
                                  ◀──────────────── └──────────────────────────────────┘
```

- **Push only.** The plugin sends; SiteWatch never connects to the WordPress site, and the plugin opens no public
  endpoints. That is why remote actions (section 7) are not built yet.
- **Transport:** every 5 minutes through WP-Cron (`sitewatch_connector_heartbeat`), immediately for new fatal
  errors and critical security events.

### Protocol

`POST {sitewatch}/api/connector/ingest.php`, JSON body, headers:

| Header | Value |
|---|---|
| `X-SiteWatch-Site` | website ID in SiteWatch |
| `X-SiteWatch-Timestamp` | Unix time; rejected if more than 300 s off |
| `X-SiteWatch-Signature` | hex `HMAC-SHA256("<timestamp>.<raw body>", secret)` |

Body: `{v: 1, reason, site_url, sent_at, status: {...}, snapshot?: {...}, events?: [...]}`

- `reason`: `hello | heartbeat | fatal | event | deactivated | disconnect`
- `status`: `wp_version, php_version, plugin_version, multisite, maintenance, loader, pulse{ok_at, error_at, probe_at, probe_status, probe_ip (1.3.0+)}, last_update{version, at, result, message}`
- `snapshot.performance` and `snapshot.php_warnings` (1.3.0+): see `SiteWatch_Connector_Insights::performance_summary()` / `warnings_summary()`
- `events[]`: `{uid, type, severity (info|warning|critical), title, data, at}`

Reply `data`: `{website, want_snapshot, received, interval, server_time, auto_update, collect_warnings, perf_sample, plugin_update: {version, package, requires, requires_php, tested, url, notes} | null, update_now}`

**Connection key** (pasted into WordPress → Settings → SiteWatch): `"swc1_" + base64url({"u": SiteWatch URL, "i": website ID, "s": 64-hex secret})`.
The secret is stored in SiteWatch encrypted with `APP_KEY` (`connector_sites.secret`).

**Self-update download:** `GET api/connector/package.php?site=&v=&expires=&sig=` where
`sig = HMAC-SHA256("package|<site>|<version>|<expires>", secret)`, valid 24 h. A query-string signature is needed
because WordPress downloads packages with a plain GET.

## 3. Files

### WordPress plugin: `wordpress-plugin/sitewatch-connector/`
SiteWatch zips this folder on the fly for download. Write **PHP 7.2-compatible** code here: no `match`,
typed properties, `str_contains`, named arguments or nullsafe operator.

| File | Role |
|---|---|
| `sitewatch-connector.php` | Main file: version constant, wiring, heartbeat, mu-loader install/remove, (de)activation hooks |
| `includes/mu-loader.php` | Copied to `wp-content/mu-plugins/sitewatch-connector-loader.php`; starts error capture and pulse before other plugins load. Its `Version` header must equal the plugin version (`loader_installed()` compares them) |
| `includes/class-sitewatch-connector-client.php` | Key parsing, signing, sending (`wp_remote_post`, or cURL/streams during a fatal error), `status()` block, state option |
| `includes/class-sitewatch-connector-errors.php` | Fatal error capture via the `wp_php_error_message` filter plus a shutdown fallback; fingerprint = type + file + line + first message line (no stack trace, numbers normalised); sends once per 10 min per fingerprint and counts repeats; stores in `wp-content/sitewatch-connector/errors.json` (option fallback) |
| `includes/class-sitewatch-connector-pulse.php` | Stamp files `ok.stamp`, `error.stamp`, `probe.stamp` (User-Agent contains `SiteWatch/`; content `"<status> <REMOTE_ADDR>"` since 1.3.0), written at most once a minute |
| `includes/class-sitewatch-connector-insights.php` | Loaded by the mu-loader. Settings in the **autoloaded** option `sitewatch_connector_insights` `{warnings, sample}` (set from the reply by `apply_reply()`, only written when changed). PHP warnings: `set_error_handler` for warning/notice/deprecated levels that counts per type+file+line (skips `@`-silenced) and passes the error on (returns the previous handler's result or `false`); merged into `warnings.json` at shutdown (max 200 places). Page speed: on 1 in `sample` requests (not cron/CLI) records ms since `$timestart`, query count, peak memory, context, status and path into `perf.json` (reservoir of 1000); with `SAVEQUERIES`, queries ≥ 50 ms with literals replaced by `?`. Files are updated under a non-blocking `flock` (a busy file is skipped). The heartbeat adds both summaries to the snapshot and deletes the files after a successful send |
| `includes/class-sitewatch-connector-health.php` | Daily snapshot: environment, updates, plugins/themes, database, cron, admins, security checks (core checksums vs WordPress.org, PHP in uploads, registration, "admin" user, file editor, XML-RPC, debug display, HTTPS, PHP EOL…) |
| `includes/class-sitewatch-connector-activity.php` | Change log hooks → queue option `sitewatch_connector_queue`; failed sign-ins aggregated in `sitewatch_connector_logins`; critical events flushed at shutdown. `record()` keeps a `by` passed in `data` |
| `includes/class-sitewatch-connector-files.php` | Watches `wp-config.php` (also one folder up), `.htaccess` and, when WordPress lives in a subfolder, the site root `.htaccess`. Fingerprints (sha256, size, mtime) in `sitewatch_connector_files`; the first run is a silent baseline. Checked by every heartbeat and at shutdown of requests where WordPress may have written them (plugin (de)activation, `upgrader_process_complete`, `insert_with_markers_inline_instructions`). Event `file_changed`: `warning` with `during` when such a request explains it, otherwise `critical` with `by: outside WordPress`. Sends file, change, sizes, mtime and 12-character hash prefixes; never contents |
| `includes/class-sitewatch-connector-updater.php` | Injects the SiteWatch offer into `update_plugins`, `plugins_api` details, `auto_update_plugin`, and runs `WP_Automatic_Updater::update('plugin', …)` from the single cron event `sitewatch_connector_self_update` |
| `includes/class-sitewatch-connector-admin.php` | Settings → SiteWatch screen: connect with key, status, "Send report now", "Disconnect", captured errors, data disclosure |
| `uninstall.php`, `readme.txt`, `index.php` files | Clean-up, WordPress readme (changelog), silence files |

WordPress options: `sitewatch_connector` (endpoint, site_id, secret, sitewatch_url, connected_at),
`sitewatch_connector_state` (last_ok, last_error, last_snapshot, want_snapshot, update, auto_update, last_update),
`sitewatch_connector_queue`, `sitewatch_connector_logins`, `sitewatch_connector_errors` (fallback),
`sitewatch_connector_files` (file fingerprints, 1.2.0+), `sitewatch_connector_insights` (autoloaded, 1.3.0+).
User meta: `sitewatch_last_login`.

### SiteWatch side

| File | Role |
|---|---|
| `app/Services/ConnectorService.php` | Keys, signature and package verification, rate limit (30/min/site, file in `storage/cache`), `ingest()`, event normalisation, alerts, `causeFor()`/`describeError()`, `state()`, `details()`, `fleet()`, `insideEvidence()`/`judgeEvidence()`, `buildZip()` |
| `app/Services/ConnectorException.php` | Rejection with HTTP status as code |
| `app/Services/VulnerabilityFeed.php` | WPVulnerability API client (`https://www.wpvulnerability.net/{plugin,theme,core}/{slug}/`, no key; the project asks only for a link back, shown on the Security tab). `normalise()` → `{closed, closed_reason, vulns: [{id, title, min, min_op, max, max_op, unfixed, score, severity, cve, link}]}`; `affects()`, `fixedIn()` |
| `app/Services/VulnerabilityScanner.php` | `scanDue()` runs in every monitoring run (budget 8 s / 25 lookups; a large site finishes over a few runs). Rescans after a new snapshot, every 12 h, and 1 h after an incomplete scan. Feed answers cached 24 h in `vulnerability_feed` (failed lookups retried after 1 h, the last good answer is kept). The report dedupes the same issue from several sources (by CVE, or title + fixed version). New items → `vulnerability` events (critical for high/critical CVSS) and one alert per site |
| `app/Repositories/ConnectorRepository.php` | `connector_sites` / `connector_events` access; fatal errors merged by fingerprint within 7 days, occurrences = max(existing, plugin running total), so re-sent reports are harmless |
| `api/connector/ingest.php` | Signed endpoint (`SW_STATELESS`: no session) |
| `api/connector/package.php` | Signed plugin download for self-update |
| `api/connector/plugin.php` | Plugin zip for signed-in users ("Download plugin") |
| `api/connector/manage.php` | `create`, `show`, `refresh`, `update`, `revoke` (permission `websites.manage`) |
| `api/connector/show.php` | Data for the website page |
| `assets/js/website-connector.js` | WordPress section on the website details page (tabs: Errors (+ PHP warnings), Security (+ vulnerabilities), Updates, Plugins & themes, Performance, Activity, Environment) and the reachability banner |
| `admin/dashboard.php` | "WordPress plugin" card (connected count, recent critical events) |
| `assets/js/websites.js` | Plug badge next to connected sites, "WordPress plugin" filter, "Alert held" label |
| `app/Monitoring/IncidentManager.php` | `setInsideEvidence()` + `insideVerdict()`: holds outage confirmation (section 5) |
| `app/Notifications/NotificationManager.php` | `wordpressError()`, `wordpressSecurity()` (adds File/Size rows for `file_changed`), `wordpressVulnerabilities()`, cause line in down alerts (`setCauseResolver`) |
| `app/Monitoring/MonitorManager.php` | Wires the cause resolver and inside-evidence provider |
| `tests/Unit/ConnectorServiceTest.php` | Key format, signatures, event validation, summaries, state, evidence decision, package signature |
| `tests/Unit/ConnectorReachabilityTest.php` | `ConnectorService::reachability()`: cached vs blocked, the cases with nothing to say, grace period after connecting |
| `tests/Unit/VulnerabilityTest.php` | Feed normalising (titles, entities, links, scores), version ranges, report matching and dedupe, new-item detection |

Database (see `database/schema.sql`, `App\Core\Migrator` steps 6 to 9):
- `connector_sites`: one row per website with a key (secret, connected_at, last_seen_at, last_reason, versions,
  updates_pending, security_issues, snapshot JSON, want_snapshot, pulse_ok_at, pulse_error_at, probe_seen_at,
  probe_status, probe_ip, want_update, update_result, update_at, vuln_count, vuln_report JSON, vuln_checked_at, vuln_incomplete).
- `vulnerability_feed`: (component, slug) → normalised answer JSON, status ok|error, fetched_at. Shared by all sites;
  rows unused for 7 days are removed by the daily cleanup (`ConnectorService::purge()`).
- `connector_events`: errors and activity (event_uid unique per site, type, severity, title, data JSON,
  fingerprint, occurrences, notified_at). Purged after 90 days by the daily cleanup.

Settings: `alert_wp_error`, `alert_security`, `alert_vulnerability` (Notifications → alert rules; the per-site
override for the last two is the website's "security" switch); `connector_auto_update`, `connector_perf_sample`
(0, 10, 20, 50, 100; default 20) and `connector_php_warnings` (default off) under Monitoring Settings → WordPress plugin.

`ConnectorService::reachability()` (shown on the website page): when WordPress served pages within 15 min, the plugin
reports, monitoring is active and checked recently, but no SiteWatch check reached WordPress for 2 h (grace period
after connecting), the state is `blocked` if the website's status is a failure or suspected down, otherwise `cached`
(a page cache or CDN answers the checks). Help shows the User-Agent token from `MONITOR_USER_AGENT`, the SiteWatch
server's public `SERVER_ADDR` if any, and the last `probe_ip`.

## 4. Done and tested

All of the following was tested end to end on a local WordPress 7.1.2 (see section 8), plus 174 unit tests, the
30 scenarios and the 32-step lifecycle test.

**Setup and connection**
- [x] Download plugin (zip built on the fly, `sitewatch-connector/` top folder)
- [x] Create / show / replace / revoke connection key in SiteWatch
- [x] Connect in WordPress → Settings → SiteWatch (key is verified with a signed `hello` before it is saved)
- [x] Wrong secret, old timestamp and another site's ID are rejected with 401; tampered package links with 403
- [x] Deactivate plugin → SiteWatch shows "Plugin deactivated"; reactivate → reporting resumes; loader removed and restored

**Error capture**
- [x] Fatal error during a request: plugin name/version, file, line and stack trace, with debug off
- [x] Fatal error while plugins load (caught by the mu-plugin loader)
- [x] Sent once, repeats counted (no flood when every request crashes); the same bug from front end and admin is one error
- [x] Server paths removed from messages
- [x] Down alerts include "Cause (from WordPress)"; separate "WordPress fatal error" alert (at most one per site per 10 min)

**Health, security, activity**
- [x] Daily snapshot + "Refresh health report" from SiteWatch
- [x] Security checks including WordPress.org core checksums and PHP files in uploads
- [x] Plugin activations/updates/deletions, theme switches, core updates, admin sign-ins, failed sign-in counts with IPs
- [x] New administrator / administrator role / site address change → immediate "Security alert"

**SiteWatch UI**
- [x] Website page WordPress section with six tabs and the "inside view" line
- [x] Plug badge and filter in the website list; dashboard card "X of Y connected"
- [x] Activity log entries (plugin connected/disconnected, key created/revoked, update requested)

**Self-update (plugin 1.1.0)**
- [x] Offer appears on WordPress's Plugins screen
- [x] Automatic update within minutes when `connector_auto_update` is on
- [x] "Update plugin" button works with automatic updates off
- [x] A broken release is rolled back by WordPress (tested with a version that crashes on load)
- [x] Mu-loader is re-copied after an update; result shown in SiteWatch ("Updated (x.y.z)" / failure message)

**Known vulnerabilities (SiteWatch 1.8.0)**
- [x] Clean site: 8 components looked up, "No known vulnerabilities"
- [x] Header-only stub `contact-form-7` 5.3.1: 5 issues after dedupe (Critical 10, fixed in 5.3.2 … Medium 5.3, fixed in 6.0.6) with CVE and Wordfence links; events logged, one alert dispatched
- [x] Security tab table, "Vulnerabilities" tile, shield icon on the Plugins & themes tab, alert rule on Notifications
- [x] Stub removed → the next scan reports 0

**File watch (plugin 1.2.0)**
- [x] 1.1.0 → 1.2.0 self-update through web WP-Cron; loader re-copied; the first heartbeat stores a silent baseline
- [x] `wp-config.php` edited and `.htaccess` created by hand → two critical `file_changed` events and security alerts ("… outside WordPress")
- [x] Permalinks saved (WordPress writes `.htaccess`) → warning "… changed while writing "WordPress" rules", by the signed-in admin

**Inside views (SiteWatch 1.9.0, plugin 1.3.0)**
- [x] 1.2.0 → 1.3.0 self-update; the reply stores `{warnings, sample}` in the autoloaded option
- [x] `?warn=1` on the crash-test plugin ×4: deprecation, warning and undefined-key warning, each counted 4 times with the plugin name, file and line; shown under Errors → PHP warnings
- [x] Sampling at 1 in 1: 8 of 9 requests recorded (one skipped by the non-blocking lock); Performance tab percentiles per context, slowest requests, SAVEQUERIES hint; files deleted after delivery
- [x] A WP-Cron heartbeat during the test re-applied SiteWatch's rate from the reply, as intended
- [x] Reachability: probe 3 h old with the website DOWN → "SiteWatch is probably blocked" with User-Agent and probe address; ONLINE → the "cache or CDN" note

**False alert protection**
- [x] Checks fail but WordPress is serving pages → alert held, "Alert held: site OK inside WordPress"
- [x] Held for more than 30 min → alert sent with a "SiteWatch is probably being blocked" note
- [x] WordPress also had a server error, or the plugin stopped reporting → normal alert immediately

## 5. How the false alert protection decides

Applied in `IncidentManager` when a failure would confirm an incident, and only for `DOWN`, `TIMEOUT`,
`HTTP_500/502/503/504` and `HTTP_ERROR` (not WordPress critical errors, database errors, DNS, SSL or redirects).

`ConnectorService::judgeEvidence()` says **healthy** only when all of these hold:
- the plugin reported within 15 min and is not deactivated or disconnected;
- WordPress served a page without a 5xx within 15 min;
- no 5xx inside WordPress and no fatal error within 15 min.

If healthy and the site was last online less than 30 min ago (`HOLD_MAX`), the website stays `SUSPECTED_DOWN`
(re-checked every minute). After 30 min the incident opens with the explanatory note. `probe_seen_at` (the last
SiteWatch check that reached WordPress) is shown for diagnosis but not used in the decision yet.

## 6. Deploying (production: Hostinger, auto-deploy from GitHub)

1. After a push that raises `Migrator::VERSION`, open **System → Updates → Update database**. Schema 8 is needed
   for 1.8.0, schema 9 for 1.9.0. The monitoring cron needs outbound HTTPS to `www.wpvulnerability.net` for vulnerability lookups.
2. Sites still on plugin **1.0.0** need one manual update to 1.1.0: SiteWatch → website → Download plugin, then
   WordPress → Plugins → Add New → Upload Plugin → "Replace current with uploaded". From 1.1.0 on, updates are
   automatic.
3. On a low-traffic site WP-Cron runs rarely, so reports are late and SiteWatch shows "Not reporting" after 30 min.
   A real server cron calling `wp-cron.php` fixes that.

### Releasing a new plugin version
1. Change the version in **three** places: `sitewatch-connector.php` (header `Version:` and
   `SITEWATCH_CONNECTOR_VERSION`), `includes/mu-loader.php` (header `Version:`), and `readme.txt` (`Stable tag` +
   changelog).
2. Push. Connected sites (1.1.0+) install it on their next heartbeat when automatic updates are on.
3. Also record SiteWatch-side changes in `App\Core\Release` and `CHANGELOG.md` (tests check they agree).

## 7. Still to do (roadmap)

Recommended order is top to bottom.

### Next (reporting only, low risk)
1. ~~Known vulnerabilities~~: done in 1.8.0. Feed choice: Wordfence's free v2 feed was retired (its URL answers 410)
   and v3 needs a key; WPScan's free tier is 25 requests a day and not for commercial use; WPVulnerability is free,
   needs no key and asks only for a link back. Possible follow-ups: an optional Wordfence v3 key as a second source,
   and a fleet-wide "vulnerable sites" list on the dashboard (`fleet()` already returns `vulnerabilities` per site).
2. ~~Watch `wp-config.php` and `.htaccess`~~: done in plugin 1.2.0. Known limit: a change made outside WordPress
   in the same request as a plugin update or permalink save is attributed to WordPress (warning, not critical).
3. ~~Recent PHP warnings~~: done in 1.9.0 / plugin 1.3.0 (off by default). Messages are sent as PHP wrote them
   (paths stripped); a warning that prints user data would carry it, as fatal error messages already do.
4. ~~Performance from inside~~: done in 1.9.0 / plugin 1.3.0. Only the latest day is kept (inside the snapshot); a
   history table with a chart would be the next step. Slow queries need `SAVEQUERIES`; a `query`-filter sampler was
   not built because the filter cannot time queries.
5. ~~Use `probe_seen_at`~~: done in 1.9.0 as the reachability banner. The outage decision (`judgeEvidence()`) is
   unchanged.

### v3: remote actions (needs design and security review)
The plugin currently accepts no commands. Suggested design: add commands to the **heartbeat reply** (pull model,
no inbound endpoint): `{commands: [{id, action, args, expires, sig}]}` signed with the site secret, an allowlist
of actions in the plugin, results reported back as events, and a per-site "Allow remote actions" switch in
WordPress (off by default).
- Clear cache (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, object cache)
- Deactivate a plugin (e.g. the one that caused a fatal error)
- Maintenance mode on/off, pausing SiteWatch alerts while it is on
- Bulk plugin updates across many sites
- Trigger a backup (UpdraftPlus API if installed)
Limit: commands only run as often as the heartbeat (5 min, depending on WP-Cron).

### v4: auto-fix (after v3)
- On a fatal error in a plugin, deactivate that plugin automatically (opt-in per site, with an alert and one-click
  re-activation). WordPress recovery mode already pauses plugins for admins only; this would do it for everyone.
- Roll back a plugin update that breaks the site (keep the previous zip or use WordPress's temp backup).

### Smaller items
- Multisite (network activation) is only partly tested.
- `wp-content/sitewatch-connector/` is closed by `.htaccess`, which nginx ignores: on nginx, `errors.json`,
  `warnings.json` and `perf.json` can be fetched by anyone who guesses the path. Consider random file names or
  storing them in options when the server is not Apache/LiteSpeed.
- The "Slow database queries" and "PHP warnings" views keep only the latest daily summary.
- The plugin's "Captured errors" list has no pagination (keeps the last 30).
- Consider sending the snapshot compressed (gzip) for very large plugin lists.
- A `SiteWatch/` probe that reaches WordPress but gets a 5xx is recorded as `probe_status`; it could be shown in the
  incident details.

## 8. Local test environment

| | |
|---|---|
| SiteWatch | http://localhost/sitewatch/ (admin@example.com / Admin12345!) |
| Test WordPress | http://localhost/wp-test/ (DB `wp_test`, admin `wpadmin` with a random password; sign in by generating an auth cookie from a script that loads `wp-load.php`, not by typing a password) |
| Test website in SiteWatch | "WP Test (local)", id 44, connected |
| Crash plugin | `wp-test/wp-content/plugins/crash-test`: `?crash=1` crashes a request; a `crash-on-load.flag` file in its folder crashes while plugins load |
| Local-only mu-plugin | `wp-test/wp-content/mu-plugins/local-test-allow-localhost.php` lets WordPress download packages from localhost (production does not need it) |
| Warnings for tests | The crash-test plugin also answers `?warn=1` with a deprecation, a warning and an undefined-key warning (switch on "Collect PHP warnings" in SiteWatch first) |
| Vulnerable plugin for tests | Create `wp-test/wp-content/plugins/contact-form-7/wp-contact-form-7.php` containing only a plugin header with `Version: 5.3.1` (no code), send a heartbeat with a snapshot, then run `VulnerabilityScanner::create()->scanDue()`. Delete the folder afterwards |

To reinstall the plugin on the test site: delete `wp-test/wp-content/plugins/sitewatch-connector` and copy
`wordpress-plugin/sitewatch-connector` there.

**Lessons from testing**
- Test self-updates through **web WP-Cron** (`wp-cron.php` over HTTP). A CLI script has its own OPcache, so
  WordPress's post-update check sees old code and misses a broken update.
- Updates run after the triggering request returns; wait for WordPress's debug log before touching plugin files,
  or you interfere with its rollback.
- XAMPP has `display_errors` on, so crash pages show PHP errors locally; live hosts normally have it off.
- WordPress's `download_url()` refuses localhost unless `http_request_host_is_external` allows it (local only).
- The local monitor cron does not run on Windows: run `php cron/monitor.php` (or `VulnerabilityScanner::create()->scanDue()`)
  to scan. `SiteWatch_Connector::heartbeat(true)` from a script that loads `wp-load.php` sends a report with a snapshot.
- `strip_tags()` cuts vulnerability titles such as "Plugin <= 5.8.3 - …" at the `<`; `VulnerabilityFeed::text()` removes real tags only.

## 9. Prompt to continue in a new chat

> Read `docs/sitewatch-connector.md` in the SiteWatch repo (C:\xampp\htdocs\sitewatch). It describes the SiteWatch
> Connector WordPress plugin and its SiteWatch side. Continue with section 7 "v3: remote actions" (start with a short
> design and security review before code). Keep plugin code PHP 7.2-compatible, add database changes as a new Migrator step with a new
> release in `App\Core\Release` and `CHANGELOG.md`, bump the plugin version in all three places, and test on the
> local WordPress at http://localhost/wp-test/.
