# SiteWatch Connector (WordPress plugin): status and handoff

What the plugin does, how it is built, what has been tested, and what is still to do. Written so that a new
session can continue the work without re-reading the whole history.

| | |
|---|---|
| Plugin version | **1.6.0** (`wordpress-plugin/sitewatch-connector/`) |
| SiteWatch version | **1.12.0**, database schema **11** |
| Last pushed commit | `212062d Plugin vUpload` (1.9.0 / plugin 1.3.0); 1.10.0–1.12.0 / plugin 1.4.0–1.6.0 not committed yet |
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
- runs **remote actions** requested in SiteWatch (clear caches, deactivate/activate/update plugins, maintenance page),
  each one only after a WordPress administrator allowed it, including rolling a plugin back to its previous version;
- **auto-fix** (opt-in): deactivates a plugin whose fatal error keeps repeating, before the next page loads;
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
  endpoints. Remote actions travel back in the reply to the plugin's own signed report (section 7).
- **Transport:** every 5 minutes through WP-Cron (`sitewatch_connector_heartbeat`), immediately for new fatal
  errors and critical security events.

### Protocol

`POST {sitewatch}/api/connector/ingest.php`, JSON body (gzip with `Content-Encoding: gzip` for reports over 64 KB
once a reply said `accepts_gzip`, plugin 1.6.0+; the signature always covers the uncompressed JSON, and SiteWatch
refuses anything that expands beyond `MAX_BODY`), headers:

| Header | Value |
|---|---|
| `X-SiteWatch-Site` | website ID in SiteWatch |
| `X-SiteWatch-Timestamp` | Unix time; rejected if more than 300 s off |
| `X-SiteWatch-Signature` | hex `HMAC-SHA256("<timestamp>.<raw body>", secret)` |

Body: `{v: 1, reason, site_url, sent_at, status: {...}, snapshot?: {...}, events?: [...]}`

- `reason`: `hello | heartbeat | fatal | event | deactivated | disconnect`
- `status` also carries `autofix{enabled, protected[]}` (1.5.0+); snapshot plugins carry `previous_version`, `updated_at` (1.5.0+)
- `status`: `wp_version, php_version, plugin_version, multisite, maintenance, loader, pulse{ok_at, error_at, probe_at, probe_status, probe_ip (1.3.0+)}, last_update{version, at, result, message}, remote{enabled, actions[]} (1.4.0+), maintenance_until (1.4.0+, unix or null)`
- `snapshot.performance` and `snapshot.php_warnings` (1.3.0+): see `SiteWatch_Connector_Insights::performance_summary()` / `warnings_summary()`
- `events[]`: `{uid, type, severity (info|warning|critical), title, data, at}`

Reply `data`: `{website, want_snapshot, received, interval, server_time, auto_update, accepts_gzip, collect_warnings, perf_sample, plugin_update: {version, package, requires, requires_php, tested, url, notes} | null, update_now, commands?: [{id, action, args_json, expires, sig}]}` (`commands` only in replies to `heartbeat`, only when the site allows at least one action)

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
| `includes/class-sitewatch-connector-errors.php` | Storage helpers for every data file (1.6.0): `data_path()`, `read_data()`, `write_data()`, `unguard()`; files are `<name>.php` starting with `<?php exit; ?>` so they print nothing over the web on any server (the folder's `.htaccess` only covers Apache/LiteSpeed); `ensure_dir()` converts the pre-1.6.0 `.json`/`.stamp` files, keeping stamp mtimes. Fatal error capture via the `wp_php_error_message` filter plus a shutdown fallback; fingerprint = type + file + line + first message line (no stack trace, numbers normalised); sends once per 10 min per fingerprint and counts repeats; stores in `wp-content/sitewatch-connector/errors.php` (option fallback) |
| `includes/class-sitewatch-connector-pulse.php` | Stamp files `ok.stamp.php`, `error.stamp.php`, `probe.stamp.php` (guarded, 1.6.0) (User-Agent contains `SiteWatch/`; content `"<status> <REMOTE_ADDR>"` since 1.3.0), written at most once a minute |
| `includes/class-sitewatch-connector-insights.php` | Loaded by the mu-loader. Settings in the **autoloaded** option `sitewatch_connector_insights` `{warnings, sample}` (set from the reply by `apply_reply()`, only written when changed). PHP warnings: `set_error_handler` for warning/notice/deprecated levels that counts per type+file+line (skips `@`-silenced) and passes the error on (returns the previous handler's result or `false`); merged into `warnings.json` at shutdown (max 200 places). Page speed: on 1 in `sample` requests (not cron/CLI) records ms since `$timestart`, query count, peak memory, context, status and path into `perf.json` (reservoir of 1000); with `SAVEQUERIES`, queries ≥ 50 ms with literals replaced by `?`. Files are updated under a non-blocking `flock` (a busy file is skipped). The heartbeat adds both summaries to the snapshot and deletes the files after a successful send |
| `includes/class-sitewatch-connector-health.php` | Daily snapshot: environment, updates, plugins/themes, database, cron, admins, security checks (core checksums vs WordPress.org, PHP in uploads, registration, "admin" user, file editor, XML-RPC, debug display, HTTPS, PHP EOL…) |
| `includes/class-sitewatch-connector-activity.php` | Change log hooks → queue option `sitewatch_connector_queue`; failed sign-ins aggregated in `sitewatch_connector_logins`; critical events flushed at shutdown. `record()` keeps a `by` passed in `data` |
| `includes/class-sitewatch-connector-files.php` | Watches `wp-config.php` (also one folder up), `.htaccess` and, when WordPress lives in a subfolder, the site root `.htaccess`. Fingerprints (sha256, size, mtime) in `sitewatch_connector_files`; the first run is a silent baseline. Checked by every heartbeat and at shutdown of requests where WordPress may have written them (plugin (de)activation, `upgrader_process_complete`, `insert_with_markers_inline_instructions`). Event `file_changed`: `warning` with `during` when such a request explains it, otherwise `critical` with `by: outside WordPress`. Sends file, change, sizes, mtime and 12-character hash prefixes; never contents |
| `includes/class-sitewatch-connector-updater.php` | Injects the SiteWatch offer into `update_plugins`, `plugins_api` details, `auto_update_plugin`, and runs `WP_Automatic_Updater::update('plugin', …)` from the single cron event `sitewatch_connector_self_update` |
| `includes/class-sitewatch-connector-remote.php` | Remote actions (1.4.0): allowlist `ACTIONS`, consent option `sitewatch_connector_remote` {enabled, actions}, `handle()` called by the heartbeat with the reply's `commands` (signature, expiry ≤ 2 h, allowed, replay via `sitewatch_connector_commands` = last 100 results; forged commands are reported but not stored), the actions themselves, and the maintenance gate on `template_redirect` (503 + `Retry-After`, editors bypass, ends by itself; autoloaded option `sitewatch_connector_maintenance`). Results go out at once as `command_result` events (with a fresh snapshot after plugin changes) |
| `includes/class-sitewatch-connector-autofix.php` | Auto-fix (1.5.0): `consider()` from the fatal handler (file flag only, when the same fingerprint from a plugin folder hit 3 times in 10 min, `errors.json` keeps the last 10 times per error); `apply()` from the mu-loader removes the plugin from `active_plugins` (not protected, not the connector, not within 24 h of an earlier auto-fix of it); `report_pending()` on `init` records the critical `plugin_auto_deactivated` event and asks for a fresh snapshot. Also `before_update()` on `upgrader_pre_install` records the version before each plugin update (`sitewatch_connector_versions`), used by the `rollback_plugin` remote action (package URL built from the slug on downloads.wordpress.org; the record is cleared after a rollback) |
| `includes/class-sitewatch-connector-admin.php` | Settings → SiteWatch screen: connect with key, status, "Send report now", "Disconnect", remote actions consent + history + "End maintenance now", captured errors, data disclosure; admin notice while the maintenance page is on |
| `uninstall.php`, `readme.txt`, `index.php` files | Clean-up, WordPress readme (changelog), silence files |

WordPress options: `sitewatch_connector` (endpoint, site_id, secret, sitewatch_url, connected_at),
`sitewatch_connector_state` (last_ok, last_error, last_snapshot, want_snapshot, update, auto_update, last_update),
`sitewatch_connector_queue`, `sitewatch_connector_logins`, `sitewatch_connector_errors` (fallback),
`sitewatch_connector_files` (file fingerprints, 1.2.0+), `sitewatch_connector_insights` (autoloaded, 1.3.0+),
`sitewatch_connector_remote`, `sitewatch_connector_commands`, `sitewatch_connector_maintenance` (autoloaded) (1.4.0+),
`sitewatch_connector_autofix` (autoloaded), `sitewatch_connector_autofix_log`, `sitewatch_connector_versions` (1.5.0+).
User meta: `sitewatch_last_login`.

### SiteWatch side

| File | Role |
|---|---|
| `app/Services/ConnectorService.php` | Keys, signature and package verification, rate limit (30/min/site, file in `storage/cache`), `ingest()`, event normalisation, alerts, `causeFor()`/`describeError()`, `state()`, `details()`, `fleet()`, `insideEvidence()`/`judgeEvidence()`, `buildZip()` |
| `app/Services/ConnectorException.php` | Rejection with HTTP status as code |
| `app/Services/RemoteActionService.php` | Remote actions: `request()` (checks the site's allowed list and validates arguments against the last snapshot: known plugin files only, never the connector itself, updates only where the snapshot shows one, 5–1440 min), `forReply()` (signs open commands, marks them sent), `recordResult()` (from `command_result` events; keeps `maintenance_until` in step), `recent()` (expires unanswered commands after 1 h) |
| `api/connector/command.php` | POST a remote action (permission `websites.remote`); logged as `connector.remote_action` |
| `api/connector/bulk.php` | POST `ids[]` (max 500), `action` in `RemoteActionService::BULK_ACTIONS` (clear_cache, update_plugins, maintenance), `args`: `RemoteActionService::bulk()` queues one command per site through `request()` and returns `{queued, skipped: [{name, reason}]}`; for update_plugins each site gets `pendingUpdates()` of its own snapshot. One activity entry per queued site |
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
| `assets/js/websites.js` | Plug badge next to connected sites, "WordPress plugin" filter, "Alert held" label, "WordPress…" bulk remote actions (`bulkRemote()`, shown with `websites.remote`) |
| `app/Monitoring/IncidentManager.php` | `setInsideEvidence()` + `insideVerdict()`: holds outage confirmation (section 5), and holds `MAINTENANCE`/`HTTP_503` while `ConnectorService::plannedMaintenance()` says a SiteWatch maintenance page is on (until 5 min after its end) |
| `app/Notifications/NotificationManager.php` | `wordpressAutoFix()` (rule `alert_wp_error`), `wordpressError()`, `wordpressSecurity()` (adds File/Size rows for `file_changed`), `wordpressVulnerabilities()`, cause line in down alerts (`setCauseResolver`) |
| `app/Monitoring/MonitorManager.php` | Wires the cause resolver and inside-evidence provider |
| `tests/Unit/ConnectorServiceTest.php` | Key format, signatures, event validation, summaries, state, evidence decision, package signature |
| `tests/Unit/RemoteActionTest.php` | Command signature format, allowed list, argument validation and refusals (unknown or own plugin, nothing to update, durations, non-allowlisted actions), planned-maintenance window |
| `tests/Unit/ConnectorReachabilityTest.php` | `ConnectorService::reachability()`: cached vs blocked, the cases with nothing to say, grace period after connecting |
| `tests/Unit/VulnerabilityTest.php` | Feed normalising (titles, entities, links, scores), version ranges, report matching and dedupe, new-item detection |

Database (see `database/schema.sql`, `App\Core\Migrator` steps 6 to 11):
- `connector_sites`: one row per website with a key (secret, connected_at, last_seen_at, last_reason, versions,
  updates_pending, security_issues, snapshot JSON, want_snapshot, pulse_ok_at, pulse_error_at, probe_seen_at,
  probe_status, probe_ip, remote_actions JSON, maintenance_until, autofix JSON, want_update, update_result, update_at, vuln_count, vuln_report JSON, vuln_checked_at, vuln_incomplete).
- `connector_commands`: remote actions (action, args JSON signed as stored, status pending|sent|done|failed|expired,
  requested_by, expires_at, result JSON). Purged after 90 days.
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

All of the following was tested end to end on a local WordPress 7.1.2 (see section 8), plus 193 unit tests, the
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

**Remote actions (SiteWatch 1.10.0, plugin 1.4.0)**
- [x] 1.3.0 → 1.4.0 self-update; allowing actions in WordPress reaches SiteWatch with the next report
- [x] Through `api/connector/command.php` from the signed-in page: clear caches (done), activate Hello Dolly (done), maintenance 15 min with a message (visitors: HTTP 503, `Retry-After`, custom text; SiteWatch classifies it as `MAINTENANCE` and the inside evidence holds the alert), maintenance off, deactivate Hello Dolly (done)
- [x] Update plugins: Akismet's header lowered to 5.0 so WordPress.org offered 5.7.2 → installed through the automatic updater, result "Updated (5.0 → 5.7.2)"
- [x] SiteWatch refuses the connector itself and non-allowlisted actions; the plugin refuses an action disallowed in WordPress after it was queued
- [x] Forged signature, other site ID, arguments changed after signing: rejected and not stored; expired: rejected; replay of a finished command: result re-sent, not run again
- [x] Remote actions tab, WordPress settings screen (consent, history), activity log entries
- [x] Bulk from the website list with the test site and a site without the plugin: clear caches (both skipped: not connected / not allowed), install updates (skipped: none pending), maintenance on → only the test site queued and served 503; maintenance off and a bulk Akismet update (header lowered to 5.0) done; single-plugin actions refused in bulk; UI flow select → "WordPress…" → confirm → toast

**Auto-fix and rollback (SiteWatch 1.11.0, plugin 1.5.0)**
- [x] 1.4.0 → 1.5.0 self-update; auto-fix switched on in WordPress reaches SiteWatch (`autofix` column)
- [x] `?crash=1` three times → the 4th request is served (200) without Crash Test; critical event and "Plugin deactivated automatically" alert at once
- [x] "Activate again" from SiteWatch, three more crashes → the plugin stays active (24-hour rule), only error alerts continue
- [x] Crash while loading (`crash-on-load.flag`, whole site 500) → after 3 requests the site serves pages again by itself
- [x] "Activate again" while the plugin still crashes on load → command failed with the error, site stayed up
- [x] Rollback: Akismet updated through SiteWatch records 5.0 → rollback to 4.0 refused → rollback to 5.0 installs the real 5.0 from WordPress.org; the record is cleared afterwards
- [x] Banner with "Activate again" (one per plugin), Deactivate/Activate/Roll back buttons on errors, auto-fix status on the Remote actions tab
- Found and fixed while testing: the rollback failed with "Could not access filesystem" when WordPress's update list did not exist (the upgrader then reports "up to date"); SiteWatch kept showing an auto-deactivated plugin as active until the next daily snapshot (a fresh snapshot is now requested)

**Smaller items (SiteWatch 1.12.0, plugin 1.6.0)**
- [x] 1.5.0 → 1.6.0: `errors.json` and the stamps converted to guarded `.php` files on the next report, pulse data still read (probe address and status)
- [x] Over the web: 403 with the folder's `.htaccess` (Apache); with it removed, as on nginx, `errors.php` and `probe.stamp.php` return 200 with 0 bytes
- [x] 82 KB report (400 extra plugin rows) sent as 8 KB gzip and accepted; small reports stay uncompressed; a 5 MB gzip bomb is refused (unit test)
- [x] Found while testing: the self-update from 1.5.0 failed with "Could not access filesystem" because WordPress's update list was missing (same cause as the rollback bug); fixed in the updater's `inject()` with `last_checked` 0 so WordPress still runs its own checks
- Probe note in incidents: unit-tested (`ConnectorService::probeNote()`); a live incident needs failing checks while WordPress answers them with a 5xx

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
   for 1.8.0, schema 9 for 1.9.0, schema 10 for 1.10.0, schema 11 for 1.11.0. The monitoring cron needs outbound HTTPS to `www.wpvulnerability.net` for vulnerability lookups.
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

### ~~v3: remote actions~~ (done: SiteWatch 1.10.0, plugin 1.4.0)

**Design and security review.** Written before the code; the implementation follows it.

*What it adds.* A SiteWatch user can ask a connected site to: clear its caches, deactivate or re-activate a
plugin, install pending plugin updates from WordPress.org, and switch a maintenance page on (5 min to 24 h) or off.

*Transport: pull, no inbound endpoint.* SiteWatch never connects to WordPress. Queued commands ride on the reply to
the plugin's signed heartbeat: `commands: [{id, action, args_json, expires, sig}]` with
`sig = HMAC-SHA256("command|<site>|<id>|<action>|<args_json>|<expires>", secret)`. `args_json` is signed as the exact
string, so both sides never re-encode it. The reply already comes over the HTTPS connection the plugin opened to the
URL in its key; the signature additionally covers plain-HTTP SiteWatch URLs and proxies that rewrite replies.

*Consent on both sides.*
- WordPress: Settings → SiteWatch → "Remote actions", **off by default**, with one checkbox per action. Only an
  administrator (`manage_options`, nonce-checked form) can change it. The plugin reports the enabled list in
  `status.remote`, so SiteWatch only offers what the site allows.
- SiteWatch: new permission `websites.remote` ("Run remote actions"). Administrators have it; Manager and Viewer do
  not, and custom roles must be granted it. Every request is written to the activity log with the user.

*Plugin-side checks, in order:* signature (constant-time compare), site ID, not expired and not more than 2 h ahead,
action enabled in WordPress, command ID not already run (the last 100 IDs and results are kept, so a re-sent command
only re-reports its result), then strict argument validation: plugin files must be keys of `get_plugins()`, never
the SiteWatch Connector itself; minutes are clamped; the maintenance message is plain text of at most 200 characters.

*Allowlist, no general-purpose actions.* No code, SQL, URLs, file paths, installs from packages, deletions or user
changes can be sent. Updates only use packages WordPress.org offered in WordPress's own update list, installed through
`WP_Automatic_Updater` (which rolls back a broken update on WordPress 6.6+).

*Threat model.* The main risk is a compromised SiteWatch server or database (secrets are encrypted with `APP_KEY`,
which lives on the same server). With remote actions, such an attacker could, on sites that opted in: deactivate
plugins (including a security plugin), show a maintenance page for up to 24 h at a time, install WordPress.org updates
and clear caches. They could not read data or run code through remote actions. Note that plugin **self-update**
(1.1.0) already lets whoever controls SiteWatch ship code to sites with automatic updates on, so SiteWatch must be
treated as trusted infrastructure either way; remote actions do not widen that. A stolen connection key lets someone pose
as the site: they could take queued commands and send fake results, but cannot make the real site do anything.
Replacing the key in SiteWatch stops that.

*Results and audit.* The plugin runs commands right after the heartbeat that delivered them and immediately sends
`command_result` events (`{command_id, action, ok, message, details}`); SiteWatch stores them on the command row and in
the activity feed. The WordPress settings screen lists the last remote actions with their result. Commands that get no
answer within 1 h are shown as "No answer" (the site did not report, or the action crashed the request).

*Maintenance.* The plugin answers front-end requests from visitors who cannot `edit_posts` with HTTP 503,
`Retry-After` and the standard "Briefly unavailable for scheduled maintenance" text (so SiteWatch classifies it as
maintenance). REST, AJAX, cron, login and wp-admin keep working. It switches itself off at the end time even if
SiteWatch is unreachable. SiteWatch stores `maintenance_until` and holds maintenance/503 alerts until 5 minutes after
it; the plugin's `status.maintenance_until` is the source of truth once it reports.

*Latency.* Commands run with the next heartbeat (every 5 minutes, depending on WP-Cron). Low-traffic sites need a real
cron job.

*Not built (yet):* bulk actions across many sites (the per-site API is ready for it), UpdraftPlus backups (not
available on the test site to verify), theme and core updates.

### Remote actions follow-ups
- UpdraftPlus backup trigger, theme and core updates (need a site with those to test).
- `update_plugins` runs inside the heartbeat request; many large updates on slow hosts can hit `max_execution_time`, and the command then shows "No answer".

### ~~v4: auto-fix~~ (done: SiteWatch 1.11.0, plugin 1.5.0)

**Design and safety review.** Written before the code. Auto-fix acts without anyone clicking, so it is built to do
little, predictably, and to be easy to undo.

*1. Deactivate a plugin that keeps crashing the site (automatic, opt-in).*
- **Consent:** WordPress → Settings → SiteWatch → Auto-fix, **off by default**, administrators only. The
  administrator can also mark plugins as **protected** (never deactivated automatically, e.g. WooCommerce on a shop,
  where a crashing checkout may still be better than no shop at all).
- **Trigger:** the same fatal error (same fingerprint) raised from a file inside one plugin's folder at least **3 times
  within 10 minutes** in web requests (not WP-CLI). One visitor refreshing can reach that; a single odd request cannot.
- **Mechanism:** the fatal error handler runs while PHP is dying and the database may be the problem, so it only writes
  a flag to `wp-content/sitewatch-connector/autofix.json`. The must-use loader, which runs before normal plugins on the
  next request, removes the plugin from `active_plugins` (the option WordPress itself uses; no files are touched), so
  the following page loads without it, even when the plugin crashes while loading. It then reports a critical
  `plugin_auto_deactivated` event, which SiteWatch alerts on straight away (alert rule "WordPress fatal error").
- **Limits against loops and surprises:** SiteWatch Connector never deactivates itself; a plugin is deactivated
  automatically at most **once per 24 hours** (if an administrator activates it again and it still crashes, the choice
  is respected and only the normal error alerts continue); network-activated plugins, must-use plugins, themes and
  core are never touched.
- **Blame:** the plugin that owns the file in which PHP stopped. When plugin A calls into plugin B wrongly, B's file
  may be where it stops; the alert names the file and line so a person can judge, and activating again is one click.
- **Why not WordPress recovery mode:** it pauses the plugin only for the administrator who opens the recovery link;
  visitors keep getting the error page.
- **Undo:** "Activate again" on the website page (remote action `activate_plugin`, which WordPress refuses if the
  plugin still fails while loading) or the Plugins screen in WordPress.

*2. Roll back a plugin update (remote action `rollback_plugin`, per click).*
- The plugin records the installed version **before** each plugin update (`upgrader_pre_install`), in
  `sitewatch_connector_versions`, and reports it in the snapshot as `previous_version` / `updated_at`.
- Rollback installs exactly that recorded version and nothing else: SiteWatch sends only `{plugin, version}`, the
  version must equal the recorded one, and the package URL is built by the plugin itself as
  `https://downloads.wordpress.org/plugin/<slug>.<version>.zip` (WordPress.org plugins only; SiteWatch cannot supply
  a URL). It is installed through `WP_Automatic_Updater` like other updates.
- Needs its own consent checkbox under Remote actions. Rolling back can reinstall a version with a known
  vulnerability; the button says which version it installs, and the Security tab shows the vulnerabilities.

*3. Deactivate from the Errors tab* (was a v3 follow-up): each fatal error from a plugin gets "Deactivate" (and "Roll
back to x" when that plugin was updated in the last 7 days), using the existing remote actions.

### Auto-fix follow-ups
- Deactivation removes the plugin from `active_plugins` without running its deactivation hook (as WordPress recovery
  mode does); plugins that schedule cron events keep them until they are activated again.
- Network-activated plugins on multisite are not handled.
- Rollback needs the plugin to be on WordPress.org; premium plugins would need their vendor's package URL.

### Smaller items
- ~~nginx could serve the plugin's data files~~: done in 1.6.0 (guarded `.php` files).
- ~~Compress large snapshots~~: done in 1.6.0 (gzip over 64 KB, only when SiteWatch advertises `accepts_gzip`).
- ~~Show `probe_status` in incident details~~: done in 1.12.0. When an outage is confirmed and WordPress answered
  SiteWatch's check with a 5xx in the last 15 minutes, the incident note says the error comes from the site itself.
- ~~Pagination for "Captured errors"~~: not needed. The plugin keeps at most 30 errors (`MAX_ENTRIES`), and SiteWatch
  keeps the full history for 90 days.
- Multisite (network activation) is only partly tested; there is no multisite install locally.
- The "Slow database queries" and "PHP warnings" views keep only the latest daily summary (a history needs a table).

## 8. Local test environment

| | |
|---|---|
| SiteWatch | http://localhost/sitewatch/ (admin@example.com / Admin12345!) |
| Test WordPress | http://localhost/wp-test/ (DB `wp_test`, admin `wpadmin` with a random password; sign in by generating an auth cookie from a script that loads `wp-load.php`, not by typing a password) |
| Test website in SiteWatch | "WP Test (local)", id 44, connected |
| Crash plugin | `wp-test/wp-content/plugins/crash-test`: `?crash=1` crashes a request; a `crash-on-load.flag` file in its folder crashes while plugins load |
| Local-only mu-plugin | `wp-test/wp-content/mu-plugins/local-test-allow-localhost.php` lets WordPress download packages from localhost (production does not need it) |
| Auto-fix for tests | Off on the test site (so `?crash=1` keeps working for error tests). To test: switch it on under Settings → SiteWatch, load `?crash=1` 3 times. The 24-hour rule is in `sitewatch_connector_autofix_log` (`last`); clear it to test again |
| Remote actions for tests | Allowed for all actions on the test site (Settings → SiteWatch). Deliver queued commands with `SiteWatch_Connector::heartbeat()` from a `wp-load.php` script. Lower a WordPress.org plugin's `Version:` header to get an update offer |
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
> Connector WordPress plugin and its SiteWatch side. All numbered roadmap items and most smaller items are done;
> continue with what is left in section 7 (follow-ups, multisite, history for daily summaries). Keep plugin code PHP 7.2-compatible, add database changes as a new Migrator step with a new
> release in `App\Core\Release` and `CHANGELOG.md`, bump the plugin version in all three places, and test on the
> local WordPress at http://localhost/wp-test/.
