# SiteWatch

**Professional WordPress website monitoring for agencies.** SiteWatch watches every client website you manage, detects
outages and WordPress-specific failures (critical errors, database errors, maintenance mode, exposed PHP fatals, SSL
problems, slow responses, redirect loops) *before the client reports them*, and alerts your team by email and Telegram —
once per incident, and once on recovery.

Built with core PHP 8.2, MySQL/MariaDB, Guzzle, PHPMailer, Monolog, Bootstrap 5.3 and Chart.js. No framework, no Node,
no Redis, no Docker: it runs on XAMPP, plain Apache/Linux and cPanel shared or VPS hosting with a single cron line.

---

## Contents

1. [Requirements](#1-requirements)
2. [Local XAMPP installation](#2-local-xampp-installation)
3. [Composer installation](#3-composer-installation)
4. [Database setup](#4-database-setup)
5. [Environment configuration (.env)](#5-environment-configuration)
6. [cPanel deployment](#6-cpanel-deployment)
7. [Cron configuration](#7-cron-configuration)
8. [SMTP setup](#8-smtp-setup)
9. [Telegram setup](#9-telegram-setup)
10. [File permissions](#10-file-permissions)
11. [Security recommendations](#11-security-recommendations)
12. [How monitoring works](#12-how-monitoring-works)
13. [Uptime calculation methodology](#13-uptime-calculation-methodology)
14. [Testing](#14-testing)
15. [Troubleshooting](#15-troubleshooting)
16. [Project structure](#16-project-structure)

---

## 1. Requirements

| Component | Requirement |
|-----------|-------------|
| PHP | **8.2 or newer** with `pdo`, `pdo_mysql`, `curl` (with SSL), `openssl`, `json`, `mbstring`. `intl` is optional (international domain names). |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Web server | Apache with `mod_rewrite` (recommended) — `.htaccess` files protect internal directories. nginx works with equivalent `location` deny rules. |
| Composer | Only needed to install dependencies (locally or on the server) |
| Cron | Ability to run `php cron/monitor.php` every minute (cPanel Cron Jobs, crontab, etc.) |
| Outbound network | HTTP/HTTPS access from the server to the monitored websites (and to SMTP / `api.telegram.org` for alerts) |

Front-end libraries (Bootstrap, Bootstrap Icons, Chart.js) are loaded from the jsDelivr CDN, so the administrator's browser
needs internet access.

---

## 2. Local XAMPP installation

1. Copy the project to `C:\xampp\htdocs\sitewatch` (or any folder under `htdocs`).
2. Make sure Apache and MySQL are running in the XAMPP control panel.
3. Install dependencies (see [Composer](#3-composer-installation)):
   ```bash
   cd C:\xampp\htdocs\sitewatch
   composer install --no-dev --optimize-autoloader
   ```
4. Open `http://localhost/sitewatch/install.php` and follow the wizard (requirements → database → tables → admin → settings).
   With XAMPP defaults use host `127.0.0.1`, user `root`, empty password and let the wizard create the `sitewatch` database.
5. Sign in at `http://localhost/sitewatch/login.php`.
6. Run the monitor manually or schedule it with Windows Task Scheduler (every minute):
   ```
   C:\xampp\php\php.exe C:\xampp\htdocs\sitewatch\cron\monitor.php
   ```

---

## 3. Composer installation

Production (no test tooling):

```bash
composer install --no-dev --optimize-autoloader
```

Development (adds PHPUnit):

```bash
composer install
```

If your host has no Composer or no shell access, run `composer install --no-dev` on your computer and upload the whole
project **including the `vendor/` directory**.

Dependencies: `guzzlehttp/guzzle` (concurrent HTTP checks), `phpmailer/phpmailer` (SMTP), `monolog/monolog` (logging),
`vlucas/phpdotenv` (.env), `dragonmantank/cron-expression` (housekeeping schedule), `composer/ca-bundle` (trusted CA
certificates for SSL verification on hosts without a system bundle).

---

## 4. Database setup

The installer creates the tables from `database/schema.sql`. To do it manually:

```sql
CREATE DATABASE sitewatch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sitewatch'@'localhost' IDENTIFIED BY 'strong-password';
GRANT ALL PRIVILEGES ON sitewatch.* TO 'sitewatch'@'localhost';
FLUSH PRIVILEGES;
```

```bash
mysql -u sitewatch -p sitewatch < database/schema.sql
```

All timestamps are stored in **UTC** and rendered in the configured application timezone (default `Asia/Karachi`).

Tables: `users`, `login_attempts`, `remember_tokens`, `websites`, `website_checks`, `incidents`, `daily_stats`,
`settings`, `notifications`, `activity_logs`, `monitor_heartbeats`.

---

## 5. Environment configuration

`install.php` writes `.env` for you. To configure manually, copy `.env.example` to `.env`:

| Key | Description |
|-----|-------------|
| `APP_ENV` / `APP_DEBUG` | Use `production` / `false` on live servers. Debug mode shows stack traces. |
| `APP_URL` | Public URL of SiteWatch, e.g. `https://monitor.agency.com` or `https://agency.com/sitewatch`. Used for links in alerts and for cookie scoping. |
| `APP_TIMEZONE` | Default display timezone (can be changed in Settings). |
| `APP_KEY` | 32 random bytes, base64. Encrypts the SMTP password and Telegram token at rest. Generate with `php -r "echo base64_encode(random_bytes(32));"`. |
| `DB_*` | Database connection. |
| `SESSION_LIFETIME` | Minutes of inactivity before an admin is signed out (default 480). |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_MINUTES` | Login rate limiting per IP / email. |
| `MONITOR_ALLOW_PRIVATE` | **Keep `false`.** Allows monitoring of private/internal addresses (see [Security](#11-security-recommendations)). |
| `MONITOR_MAX_PER_RUN` | Maximum due websites processed by one cron run (default 300). |

Runtime settings (thresholds, SMTP, Telegram, retention, alert rules) live in the database and are edited in the UI.

---

## 6. cPanel deployment

1. **PHP version** – In cPanel → *MultiPHP Manager* (or *Select PHP Version*) set the domain/subdomain to **PHP 8.2+**
   with the extensions `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json` and (optionally) `intl` enabled.
2. **Upload files** – Upload the project (including `vendor/`, or run `composer install --no-dev` over SSH) to the
   document root of a dedicated subdomain, e.g. `/home/USERNAME/monitor.agency.com/`. A sub-folder of an existing site
   (`/home/USERNAME/public_html/sitewatch/`) also works; the app detects its base path.
   *Document root note:* the whole project is designed to live inside the document root — `.htaccess` files block web
   access to `app/`, `config/`, `database/`, `storage/`, `cron/`, `vendor/`, `tests/`, `.env` and `composer.*`. If your
   host lets you place the project above the document root, you can keep only the public entry files inside it, but this
   is not required.
3. **Database** – cPanel → *MySQL® Databases*: create a database (`USERNAME_sitewatch`), create a user, and add the user
   to the database with **ALL PRIVILEGES**. cPanel prefixes names with your account name.
4. **Run the installer** – open `https://monitor.agency.com/install.php`, enter the database credentials (host is usually
   `localhost`), create the admin account and set the application URL/timezone. The wizard writes `.env`, creates the
   tables and `storage/install.lock`.
5. **Permissions** – see [File permissions](#10-file-permissions). `storage/` must be writable by PHP.
6. **Cron** – see [Cron configuration](#7-cron-configuration).
7. **SMTP / Telegram** – configure under *System → Notifications* and send the test messages.
8. **HTTPS** – enable AutoSSL / Let's Encrypt for the subdomain and force HTTPS (cPanel → Domains → *Force HTTPS
   Redirect*). Session cookies are marked `Secure` automatically when served over HTTPS.
9. **First login** – sign in with the admin account created by the installer, add a website, click *Check Now*, then
   watch the *Monitoring Engine* indicator in the sidebar turn to *Running* after the first cron execution.
10. Optionally delete `install.php` from the server.

---

## 7. Cron configuration

SiteWatch needs **one** cron job running every minute. The application itself decides which websites are due based on
their individual intervals (1–30 minutes), prevents overlapping runs with a file lock, records a heartbeat, refreshes SSL
information gradually and runs the daily cleanup automatically.

```
* * * * * /usr/local/bin/php /home/USERNAME/monitor.agency.com/cron/monitor.php >/dev/null 2>&1
```

Optional additional jobs (both are also performed automatically by `monitor.php`, so they are a matter of preference):

```
15 3 * * * /usr/local/bin/php /home/USERNAME/monitor.agency.com/cron/cleanup.php   >/dev/null 2>&1
30 4 * * * /usr/local/bin/php /home/USERNAME/monitor.agency.com/cron/ssl-check.php >/dev/null 2>&1
```

**Finding the correct PHP binary.** Do not assume `/usr/local/bin/php` exists or is PHP 8.2. Determine the path with one of:

* cPanel → *Cron Jobs* often shows the recommended command prefix.
* Over SSH: `which php`, `php -v`, `ls /opt/cpanel/ea-php*/root/usr/bin/php` (EasyApache hosts: e.g.
  `/opt/cpanel/ea-php82/root/usr/bin/php`), or `ls /usr/local/php*/bin/php`.
* Create a temporary file `phpinfo.php` with `<?php echo PHP_BINARY;` and run it from the cron with `php -f`.
* CloudLinux "Select PHP Version" hosts: `/opt/alt/php82/usr/bin/php`.

Always use the **absolute path** to `cron/monitor.php`. To verify, run the command over SSH — it prints a summary such as
`Checked 12 website(s), 1 failing, 0 incident(s) opened, 0 resolved in 3.2 s.`. The dashboard sidebar shows
*Monitoring Engine · Running* with the time of the last run; if the cron stops, it shows *Not Running* after
`heartbeat_threshold_minutes` (default 3).

Logs: `storage/logs/cron.log`, `monitor.log`, `notifications.log`, `app.log`, `error.log` (rotated daily).

---

## 8. SMTP setup

*System → Notifications → Email*:

| Field | Example |
|-------|---------|
| SMTP Host | `smtp.gmail.com`, `smtp.office365.com`, `smtp.sendgrid.net`, `mail.agency.com` |
| Port / Encryption | `587` + STARTTLS (most providers) or `465` + SSL/TLS |
| Username / Password | Mailbox or API credentials. The password is stored **encrypted** with `APP_KEY` and never returned to the browser. |
| From Email / Name | Sender identity (some providers require it to match the account). |
| Recipients | One or more addresses, comma separated. |

Click **Send Test Email**. Failures are shown with the SMTP error and recorded in the delivery log.

---

## 9. Telegram setup

1. Chat with [@BotFather](https://t.me/BotFather), send `/newbot`, and copy the **bot token** (`123456789:AAF…`).
2. Find the **chat ID**:
   * Personal: message the bot, then open `https://api.telegram.org/bot<TOKEN>/getUpdates` — use `message.chat.id`.
   * Group: add the bot to the group, send a message, then read `getUpdates` (group IDs are negative, e.g. `-1001234567890`).
   * Channel: add the bot as an administrator; use the channel ID or `@channelusername`.
3. *System → Notifications → Telegram*: enter token and chat ID, enable, save, and click **Test Telegram Alert**.

Telegram failures never interrupt monitoring; they are logged in the delivery log and `notifications.log`.

---

## 10. File permissions

```
storage/            775 (writable by the PHP user)  — logs, cache, locks, install.lock
storage/logs/       775
storage/cache/      775
storage/locks/      775
.env                640 (readable by PHP only)
everything else     644 files / 755 directories
```

On cPanel (suPHP/LSAPI) PHP runs as your account user, so the default upload permissions are usually correct. Never make
`.env` world-readable and never expose `storage/` publicly (the included `.htaccess` files deny access).

---

## 11. Security recommendations

* **HTTPS only.** Run SiteWatch on an HTTPS subdomain; cookies become `Secure` automatically.
* **Keep `APP_DEBUG=false` in production.** Errors are logged to `storage/logs/error.log`; visitors never see stack traces.
* **Protect `.env`.** It contains database credentials and `APP_KEY`. `.htaccess` blocks it on Apache; verify by opening
  `https://your-host/.env` — it must return *403 Forbidden*.
* **SSRF protection is mandatory and on by default.** SiteWatch makes HTTP requests to administrator-supplied URLs, so every
  target — and every redirect hop — is resolved to IP addresses first. Loopback, private (10/8, 172.16/12, 192.168/16),
  link-local/metadata (169.254.0.0/16, `fd00:ec2::254`), CG-NAT, multicast, reserved and IPv6 equivalents (including
  IPv4-mapped, NAT64 and 6to4 forms) are rejected, and the resolved addresses are pinned with `CURLOPT_RESOLVE` to defeat DNS
  rebinding. Set `MONITOR_ALLOW_PRIVATE=true` only in a trusted internal network where you intentionally monitor intranet
  sites.
* **Authentication.** Passwords are hashed with `password_hash()` (bcrypt/argon2 as configured by PHP). Login is rate limited
  (5 failures per 15 minutes per IP/email). Sessions use strict mode, HttpOnly + SameSite=Lax cookies, idle timeout and are
  regenerated on login. "Remember me" uses rotating selector/validator tokens with hashed validators.
* **CSRF.** Every state-changing request (forms and JSON API) requires a session-bound token (`_token` field or
  `X-CSRF-Token` header). Logout is POST-only.
* **Output escaping.** All dynamic HTML is escaped (`e()` on the server, `SW.escape()` in the browser). A Content Security
  Policy with per-request nonces blocks inline scripts.
* **CSV safety.** Exports neutralise spreadsheet formula injection (`=`, `+`, `-`, `@` prefixes) and uploads are validated
  by extension, MIME type and size.
* **Credentials at rest.** SMTP password and Telegram token are AES-256-GCM encrypted with `APP_KEY`. Changing `APP_KEY`
  invalidates them (re-enter them in Notifications).
* **Delete `install.php`** after installation, or at least keep `storage/install.lock` in place.
* Keep PHP and Composer dependencies updated (`composer update --no-dev`).

---

## 12. How monitoring works

1. **Scheduling** – every minute `cron/monitor.php` selects websites whose `next_check_at` has passed (respecting each
   website's own interval), up to `MONITOR_MAX_PER_RUN`.
2. **Concurrent probes** – websites are checked in batches of `concurrency` (default 15) using Guzzle promises and
   `Utils::settle()`, so one failing or slow website never blocks the others. Each request uses a browser-like User-Agent,
   connect/request timeouts, SSL verification (never disabled globally), a redirect limit and manual redirect following so
   every hop is SSRF-validated.
3. **Classification** – `StatusClassifier` turns the raw probe into a status using this priority: DNS failure → connection
   failure → timeout → SSL error → redirect loop/limit → recognised failure page in the body (WordPress critical error,
   database error, maintenance mode, exposed PHP fatal, generic server error page — even when HTTP 200) → HTTP 5xx → HTTP
   4xx (401/403/429 are warnings: the site responded but blocked the monitor) → critical performance → slow → SSL
   expiring → ONLINE. HTTP 500 is kept as supporting diagnostics when a more specific root cause is found.
4. **Error detection is conservative** – only specific signatures are used (`There has been a critical error on this
   website`, `Error establishing a database connection`, `Briefly unavailable for scheduled maintenance`, `Fatal error:`,
   `Allowed memory size`, `Maximum execution time`, `Call to undefined function`, …). Phrases that a blog article could
   quote are only accepted on small error-style pages or 5xx responses, and PHP fatals only where PHP actually prints them
   (before `<html>`, after `</body>`, or where output was cut off). Generic words like "Warning" or "Error" never trigger.
5. **Confirmation (false-positive protection)** – a failure is only confirmed after `failure_threshold` consecutive failed
   checks (default 3; per-website override available). Until then the website shows *Suspected Down* and no alert is sent.
   On confirmation an **incident** is opened with `started_at` set to the first failed check and **one** alert is sent.
6. **Recovery** – after `recovery_threshold` consecutive successful checks (default 2) the incident is resolved and **one**
   recovery alert is sent. Repeated checks during an incident never send duplicate alerts; a changing root cause updates the
   incident's diagnostics instead.
7. **SSL** – certificates are inspected with native OpenSSL streams (SNI, verified chain and hostname). Expiry alerts are
   sent once per threshold (30, 14, 7 days, expired) and reset when the certificate is renewed. Certificate failures during
   the HTTP check produce an *SSL Error* incident.
8. **Heartbeat** – every run writes to `monitor_heartbeats`; the UI shows *Running* / *Not Running* / *Problem Detected*.
9. **Retention** – raw checks older than `check_retention_days` (default 30) are deleted in batches; daily aggregates and
   incidents are kept indefinitely.

Alert rules exist globally (Notifications) and per website (Down, Critical, Slow, SSL, Recovery). Both must allow an alert
for it to be sent.

---

## 13. Uptime calculation methodology

```
uptime % = up_checks / total_checks × 100
```

* Every check is stored with `is_up`. A check counts as **down only when it belongs to a confirmed incident**. When the
  failure threshold is reached, the preceding failures of the same streak are retro-actively marked as down (the incident
  started at the first failure). An isolated blip that never becomes an incident does **not** reduce uptime — consistent
  with the alerting philosophy that a single failed request may be the monitor's network, not the website.
* Paused websites and periods without checks contribute nothing: **no data ≠ downtime**.
* Rolling **24h** figures are computed from raw checks. **7d / 30d / 90d / lifetime** figures come from `daily_stats`
  (calendar days in the application timezone, rebuilt after every check), so they survive raw-check retention.
* Reports also show **downtime duration** derived from incident start/resolve times (overlap-aware within the date range),
  incident counts, and average / minimum / maximum response times of successful checks.
* Fleet-wide numbers weight every check equally.

---

## 14. Testing

```bash
composer install            # includes PHPUnit
vendor/bin/phpunit          # unit tests: URL normalisation, SSRF guard, error detector, classifier
php tests/scenarios.php     # engine scenarios against a local fixture server (HTTP 200/404/5xx, WP critical error on
                            # 200 and 500, DB error, maintenance, PHP fatal, slow, timeout, redirect chain/loop/limit,
                            # 403, connection refused, DNS failure, SSRF blocking, concurrency)
php tests/scenarios.php --network   # additionally: expired / self-signed / wrong-host certificates (badssl.com)
php tests/lifecycle.php     # integration test against the configured database: 3-failure confirmation, incident
                            # creation, alert deduplication, pending recovery, resolution, uptime accounting,
                            # single blip, pause/resume, cron cycle and heartbeat
```

Manual checks worth doing after deployment: log in, add a website, *Check Now*, watch the engine indicator after the cron's
first run, send test email/Telegram, import a CSV, export CSVs, and view the dashboard on a phone in dark mode.

---

## 15. Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| *Monitoring Engine · Not Running* | Cron is not executing. Run the cron command over SSH, check the PHP path, look at `storage/logs/cron.log` and `error.log`. |
| `SiteWatch is not installed yet` when running cron | `storage/install.lock` or `.env` is missing — finish the installer. |
| *Another monitoring process is still running* in cron.log | Normal when a run takes longer than a minute (many timeouts). If it persists, a stuck process holds `storage/locks/monitor.lock`; the OS releases `flock()` locks when the process dies, so kill the stale PHP process. |
| Some HTTPS sites show *SSL Error · untrusted issuer* although browsers accept them | The server's CA bundle is outdated (XAMPP ships a 2022 file). SiteWatch automatically uses whichever is newer: the system bundle (`curl.cainfo` / `openssl.cafile`) or the Mozilla bundle shipped with `composer/ca-bundle` — keep dependencies updated with `composer update --no-dev`, or update the OS `ca-certificates` package. |
| `"host" resolves to a private or internal address; monitoring blocked` | SSRF protection. Only enable `MONITOR_ALLOW_PRIVATE=true` for trusted internal networks. |
| Website shows *Warning · HTTP 403* | The site's firewall / bot protection (e.g. Cloudflare) blocks the monitor. Allow-list the server IP or the `SiteWatch` User-Agent. |
| Website shows *Suspected Down* but is fine in the browser | A transient failure; it clears on the next successful check. Persistent suspected-down means intermittent failures — see the check history. |
| Test email fails with "SMTP connect() failed" | Wrong host/port/encryption, or the hosting provider blocks outbound SMTP (common on shared hosting: use port 587 STARTTLS or the provider's relay). |
| Telegram: `chat not found` | Start a conversation with the bot first (personal) or add the bot to the group/channel; check the sign of the chat ID. |
| `Composer dependencies are missing` | Run `composer install --no-dev` or upload `vendor/`. |
| Blank page / 500 error | Set `APP_DEBUG=true` temporarily, or read `storage/logs/error.log`. Check PHP version ≥ 8.2 and file permissions. |
| Times are off by several hours | Set the correct timezone under *General Settings*. Storage is UTC. |
| Login blocked: *Too many failed sign-in attempts* | Wait `LOGIN_LOCKOUT_MINUTES` or delete rows from `login_attempts`. |
| Forgot admin password | Run `php -r 'echo password_hash("NewPassword123", PASSWORD_DEFAULT);'` and update `users.password_hash` in the database. |

---

## 16. Project structure

```
admin/            Dashboard, websites, incidents, response times, reports, notifications, settings, activity, profile
api/              JSON endpoints (Fetch API) grouped by area: dashboard, websites, incidents, reports, settings, activity, monitoring, notifications, profile
app/Core/         App container, Config, Database (PDO), Session, Auth, CSRF, Crypto, Lock, Validator, Request/Response, UrlNormalizer, ErrorHandler
app/Monitoring/   WebsiteMonitor (Guzzle), SsrfGuard, ErrorDetector, StatusClassifier, SSLChecker, IncidentManager, MonitoringScheduler, UptimeCalculator, MonitorManager, Status
app/Notifications NotificationManager, EmailNotifier (PHPMailer), TelegramNotifier, AlertMessage, NotifierInterface
app/Repositories/ Website, Check, Incident, DailyStats, Settings, User, Activity, Notification, Heartbeat repositories (PDO prepared statements)
app/Services/     DashboardService, WebsiteService (CRUD/import/export), ReportService, ActivityService, MaintenanceService, ServiceFactory
assets/           app.css (light/dark design system), vanilla JS per page
config/           app.php, database.php (read from .env)
cron/             monitor.php (every minute), cleanup.php (daily), ssl-check.php (daily, optional)
database/         schema.sql
includes/         header.php, sidebar.php, footer.php, website-form.php, report-layout.php
storage/          logs/, cache/, locks/, install.lock (protected)
tests/            PHPUnit unit tests, fixture server, scenario and lifecycle scripts
install.php       Installation wizard   ·   login.php / logout.php / index.php
```

Scale guidance: the default configuration comfortably handles roughly 50–500 websites depending on intervals and server
resources (500 sites at 5-minute intervals ≈ 100 checks per minute in batches of 15). Concurrency, timeouts, retention and
batch size are configurable; the `MonitorManager` / `WebsiteMonitor` separation allows a queue/worker architecture to be
introduced later without changing the monitoring logic.
