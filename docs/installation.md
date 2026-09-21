# SiteWatch installation and operations guide

[Back to the project overview](../README.md)

Detailed setup, configuration, monitoring behavior, and administration for the current self-hosted application.

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
10. [WhatsApp setup](#10-whatsapp-setup)
11. [Discord setup](#11-discord-setup)
12. [Core Web Vitals & screenshots](#12-core-web-vitals--screenshots)
13. [File permissions](#13-file-permissions)
14. [Security recommendations](#14-security-recommendations)
15. [How monitoring works](#15-how-monitoring-works)
16. [Uptime calculation methodology](#16-uptime-calculation-methodology)
17. [Testing](#17-testing)
18. [Troubleshooting](#18-troubleshooting)
19. [Users, roles & permissions](#19-users-roles--permissions)
20. [Domain & hosting details](#20-domain--hosting-details)
21. [Project structure](#21-project-structure)

---

## 1. Requirements

| Component | Requirement |
|-----------|-------------|
| PHP | **8.2 or newer** with `pdo`, `pdo_mysql`, `curl` (with SSL), `openssl`, `json`, `mbstring`. `intl` is optional (international domain names). |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Web server | Apache with `mod_rewrite` (recommended) — `.htaccess` files protect internal directories. nginx works with equivalent `location` deny rules. |
| Composer | Only needed to install dependencies (locally or on the server) |
| Cron | Ability to run `php cron/monitor.php` every minute (cPanel Cron Jobs, crontab, etc.) |
| Outbound network | HTTP/HTTPS access from the server to the monitored websites; to the alert channels you enable (SMTP, `api.telegram.org`, `green-api.com` / `graph.facebook.com` / `api.callmebot.com`, `discord.com`); and, for Core Web Vitals and screenshots, `googleapis.com` and `s0.wp.com` / `image.thum.io` |

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

Tables: `roles`, `users`, `login_attempts`, `remember_tokens`, `websites`, `website_checks`, `incidents`, `daily_stats`,
`settings`, `notifications`, `activity_logs`, `monitor_heartbeats`, `domain_info`, `website_vitals`, `website_screenshots`.

**Updating after a deploy (e.g. GitHub auto-deployment).** `database/schema.sql` always describes the latest schema; each
installation keeps its own data and records its schema version in `settings.schema_version`. When newly deployed code needs
database changes, SiteWatch sends administrators to **System → Updates**, which lists what will change and applies it with
one click and keeps an update history. Other users see a note to ask an administrator, and the monitoring cron keeps running
meanwhile. Updates only add or alter tables and columns in *that server's* database — websites, history, users and settings
are kept, and nothing is copied from another installation such as a local copy. Each step checks what already exists, so an
interrupted update can be run again. Back up the database first.

Alternatives: run the update over SSH, or set `DB_AUTO_MIGRATE=true` in `.env` to apply updates automatically on the first
request or cron run after each deploy.

```bash
php database/migrate.php
```

The database user needs `ALTER` and `CREATE` privileges (cPanel's "ALL PRIVILEGES" includes them). Schema version 2
creates the `roles` and `domain_info` tables and gives every existing account the **Administrator** role. Version 3 adds
the `website_vitals` and `website_screenshots` tables and the `ttfb` columns.

---

## 5. Environment configuration

`install.php` writes `.env` for you. To configure manually, copy `.env.example` to `.env`:

| Key | Description |
|-----|-------------|
| `APP_ENV` / `APP_DEBUG` | Use `production` / `false` on live servers. Debug mode shows stack traces. |
| `APP_URL` | Public URL of SiteWatch, e.g. `https://monitor.agency.com` or `https://agency.com/sitewatch`. Used for links in alerts and for cookie scoping. |
| `APP_TIMEZONE` | Default display timezone (can be changed in Settings). |
| `APP_KEY` | 32 random bytes, base64. Encrypts every stored alert credential at rest (SMTP password, Telegram token, WhatsApp keys, Discord webhook URL). Generate with `php -r "echo base64_encode(random_bytes(32));"`. |
| `DB_*` | Database connection. |
| `SESSION_LIFETIME` | Minutes of inactivity before an admin is signed out (default 480). |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_MINUTES` | Login rate limiting per IP / email. |
| `MONITOR_ALLOW_PRIVATE` | **Keep `false`.** Allows monitoring of private/internal addresses (see [Security](#14-security-recommendations)). |
| `MONITOR_MAX_PER_RUN` | Maximum due websites processed by one cron run (default 300). |
| `DB_AUTO_MIGRATE` | `false` (default): database updates for newly deployed code wait for an administrator under *System → Updates*. `true`: they are applied automatically by the first request or cron run. |

Runtime settings (thresholds, alert channels, retention, alert rules) live in the database and are edited in the UI.

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
5. **Permissions** – see [File permissions](#13-file-permissions). `storage/` must be writable by PHP.
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

Recommended daily job for [domain & hosting details](#20-domain--hosting-details). Without it, details are still looked
up when someone opens a website or the Domains page, but expiry dates are not kept current in the background:

```
45 4 * * * /usr/local/bin/php /home/USERNAME/monitor.agency.com/cron/domain-check.php >/dev/null 2>&1
```

Recommended hourly job for [Core Web Vitals & screenshots](#12-core-web-vitals--screenshots). Both are rate limited by
their providers, so this processes a small batch per run and works through the fleet steadily. Without it nothing is
collected in the background, though the per-website **Measure now** and **Capture now** buttons still work:

```
20 * * * * /usr/local/bin/php /home/USERNAME/monitor.agency.com/cron/vitals-check.php >/dev/null 2>&1
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

## 10. WhatsApp setup

WhatsApp alerts go out through one of three providers, selected under *Notifications → WhatsApp*. Enter the destination
number in international format (`+923001234567`) for any of them.

| Provider | Cost | Setup | Reaches | Watch out for |
|----------|------|-------|---------|---------------|
| **GREEN-API** | Free Developer plan, no card, no expiry | Scan a QR code | 3 chats | Bridges WhatsApp Web, so the linked number carries some restriction risk |
| **Cloud API** | Free test number, no card | Meta app + approved template | 5 verified numbers | Business-initiated alerts need a template |
| **CallMeBot** | Free, personal use | Message a bot, wait for a key | Only the number that activated it | The activation bot frequently never replies |

### GREEN-API — free plan, quickest to set up

Alerts are sent **from your own WhatsApp number**, so there are no message templates to get approved and no business
profile to create. The free *Developer* plan does not expire and needs no payment method.

1. Create an account at [green-api.com](https://green-api.com/en/) and add an instance on the **Developer** plan.
2. Open the instance and scan the QR code with the WhatsApp the alerts should be sent *from*. Wait until the instance
   state reads `authorized`.
3. Copy `idInstance`, `apiTokenInstance` and `ApiUrl` from the console.
4. *Notifications → WhatsApp*: choose **GREEN-API**, paste all three, enter the destination number, enable, save, then
   **Send test message**.

The token is stored encrypted with `APP_KEY` and never returned to the browser. `ApiUrl` is optional — newer accounts get
a numbered host such as `https://7103.api.greenapi.com`, and anything that is not a GREEN-API host is ignored in favour
of `https://api.green-api.com`.

Two limits are worth knowing. The free plan talks to **three chats**, which is ample for alerting but means you cannot
fan out to a whole team. And because it works through WhatsApp Web rather than an official API, link a number you would
not mind having restricted, and keep alert volume modest.

### WhatsApp Cloud API — Meta's official platform

The official route, and the one with no risk to a personal WhatsApp account. A **test number** from the Meta developer
dashboard is free and needs no card, but delivers only to up to five recipient numbers you verify there. Moving to a
real business number means Meta meters the messages once it passes its free allowance. Either way **alerts are
business-initiated**, so they fall outside the free 24-hour service window.

1. Create a Meta app with the WhatsApp product, and note the **phone number ID** (a numeric ID, not the phone number).
2. Generate a **permanent** system-user access token — the temporary token in the dashboard expires after 24 hours.
3. Submit a **utility template** with one body parameter, e.g. `SiteWatch alert: {{1}}`, and wait for approval.
4. Enter the phone number ID, token, template name and language code (`en_US`), enable, save, then send a test message.

The alert is folded onto one line before it is passed as `{{1}}`, because Meta rejects newlines, tabs and long runs of
spaces in template parameters. Leaving the template name empty sends plain text instead, which only arrives if the
recipient messaged your business number within the last 24 hours — that is a testing convenience, not a setup for alerts.

### CallMeBot — free, but often cannot be activated

[CallMeBot](https://www.callmebot.com/blog/free-api-whatsapp-messages/) is a free relay needing no account and no
business profile, and it delivers only to the one number that authorised it.

1. Save the WhatsApp number `+34 623 78 95 80` to your contacts as **CallMeBot**. Check the
   [CallMeBot page](https://www.callmebot.com/blog/free-api-whatsapp-messages/) in case that number has changed.
2. From the phone you want alerts on, send it: `I allow callmebot to send me messages`.
3. It should reply with an API key within a couple of minutes.
4. *Notifications → WhatsApp*: choose **CallMeBot**, enter the number and the key, enable, save, then send a test message.

The activation bot is run as a hobby service and **regularly answers nothing at all**. Sending the phrase repeatedly does
not help and may get you rate limited. If no key arrives, send `Recover APIKey` to the same contact; if that is silent
too, the service is not accepting you right now and GREEN-API or the Cloud API is the way through. Alerts are trimmed to
900 characters here because CallMeBot receives them inside a URL.

---

## 11. Discord setup

Discord webhooks are free, need no bot application and no OAuth.

1. In Discord, open the channel you want alerts in → **Edit Channel** → **Integrations** → **Webhooks**.
2. Create a webhook and click **Copy Webhook URL**.
3. *Notifications → Discord*: paste the URL, enable, save, and click **Send test message**.

Optionally set a **bot name** to override the one configured on the webhook, and a **mention** (`@here`, `@everyone`
or a role such as `<@&123456789012345678>`) that is prefixed to every alert. Only that mention is allowed to ping:
an `@` inside a website name or an error message is never resolved.

Alerts arrive as a rich embed coloured by severity — red for an outage, green for a recovery, amber for a certificate
warning — with the same fields as the email. The webhook URL is stored encrypted, because anyone holding it can post to
the channel; for that reason it is never shown again after saving. Discord allows about 30 messages per minute per
webhook and answers `429` when that is exceeded; SiteWatch retries once and then records the failure.

---

## 12. Core Web Vitals & screenshots

### Why this needs an external service

LCP, CLS and INP describe what a browser does while it renders a page. Nothing measurable with cURL from PHP can stand
in for them, and shared hosting has no headless browser, so SiteWatch asks **Google PageSpeed Insights** to run the
measurement and stores the answer. Screenshots have the same problem and are produced by a free rendering service.

**Time to first byte is the exception.** SiteWatch measures TTFB itself, from cURL, on *every* check of every website,
and stores it on the check row. That works with no external service, no quota and no configuration, so TTFB history is
as granular as your check interval even if you never enable anything below.

### Core Web Vitals (PageSpeed Insights)

Each run returns two independent sets of numbers:

| | Where it comes from | Contains | Notes |
|---|---|---|---|
| **Lab** | A Lighthouse render Google performs on demand, in a throttled emulated browser | Score, LCP, CLS, TBT, TTFB, FCP, Speed Index, TTI | Always available and reproducible — the right thing for spotting a regression |
| **Field** | The 75th percentile of real Chrome users over the last 28 days (CrUX) | LCP, **INP**, CLS, TTFB, FCP | What Google actually ranks on, and the only source of INP. Absent until a site has enough traffic |

Lighthouse cannot measure INP, because there is no user to interact with the page. It reports **Total Blocking Time**
instead, which SiteWatch shows as the lab stand-in rather than mislabelling it as INP. If a website has no field data,
it has no INP, and that is a property of its traffic, not a fault in the setup.

**Setup.** *Monitoring Settings → Core Web Vitals*: enable it, paste a Google API key, choose mobile, desktop or both,
and set the re-check interval.

A **free Google API key is required in practice.** PageSpeed can be called without one, but the anonymous quota is a
pool shared by every anonymous caller on the internet and is almost always exhausted — you will simply record failures.
Create a project in the [Google Cloud console](https://console.cloud.google.com/apis/credentials), enable the
**PageSpeed Insights API**, and create an API key. No billing account is needed. The key is stored encrypted with
`APP_KEY` and never returned to the browser.

Keep the interval at a day or so. A Lighthouse run takes 10–40 seconds per page, and "mobile and desktop" is two
requests per website per run.

### Screenshots

*Monitoring Settings → Screenshots*. Three providers, all free:

| Provider | Notes |
|----------|-------|
| **WordPress mShots** | Default. No key. Renders in the background, so a brand new URL returns a placeholder and succeeds on the next attempt — SiteWatch detects that and reports it rather than storing the placeholder |
| **thum.io** | No key, renders immediately, rate limited by IP |
| **PageSpeed render** | Reuses the final render from a Core Web Vitals run, so no extra request — but needs vitals enabled and is only as fresh as that schedule |

All three are sent the address of each monitored website. Images are written to `storage/screenshots`, which is outside
the web root and denied by `.htaccess`; they are served only to signed-in users through `api/websites/screenshot.php`.

**On the capture interval.** The interval list includes **Every check**, which captures a screenshot each time a
website is checked. Be deliberate about it: 30 websites on 5-minute checks is roughly 8,600 requests a day to a free
service, which they rate limit. An hour or more is the sensible setting, and the **Capture now** button on a website's
details page gives you an image of that site this second when you actually need one.

Old images are removed by the cleanup job, by age and by a per-website cap, whichever is reached first.

### Cron

Both jobs run from one command. Hourly is right — it works through the fleet a batch at a time and does nothing for
websites that are not yet due:

```
20 * * * * /usr/local/bin/php /path/to/sitewatch/cron/vitals-check.php >/dev/null 2>&1
```

Screenshots set to "every check" are captured by `cron/monitor.php` instead and are skipped by this job.

---

## 13. File permissions

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

## 14. Security recommendations

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
  regenerated on login. "Remember me" uses rotating selector/validator tokens with hashed validators. A password reset or
  deactivation signs the account out of every browser immediately.
* **Authorisation.** Every page and API endpoint checks the signed-in user's role on the server; hiding buttons in the
  interface is only a convenience. See [Users, roles & permissions](#19-users-roles--permissions).
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

## 15. How monitoring works

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

## 16. Uptime calculation methodology

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

## 17. Testing

```bash
composer install            # includes PHPUnit
vendor/bin/phpunit          # unit tests: URL normalisation, SSRF guard, error detector, classifier, permissions,
                            # registrable domains, WHOIS / RDAP parsing, hosting / CDN detection
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

## 18. Troubleshooting

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
| WhatsApp: CallMeBot never sends an API key | Its activation bot often does not reply. Try `Recover APIKey` once, then switch to GREEN-API or the Cloud API — repeating the activation phrase will not help. |
| WhatsApp: CallMeBot rejects the message | The key is tied to one number: confirm the destination number is the phone that sent the activation message, and that the key was copied in full. |
| WhatsApp: GREEN-API `HTTP 401` | The instance ID or token is wrong, or the instance was deleted in the console. |
| WhatsApp: GREEN-API `HTTP 466` | The free Developer plan covers three chats and a monthly quota; the destination is outside it, or the quota is spent. |
| WhatsApp: GREEN-API accepted nothing | The instance is not `authorized` — re-scan the QR code in the console; a linked phone that stays offline too long drops the session. |
| WhatsApp: `Recipient phone number not in allowed list` | A Cloud API test number only delivers to numbers you added in the Meta dashboard. For any other recipient the number must be fully registered. |
| WhatsApp: nothing arrives through the Cloud API | Business-initiated messages need an approved template. Set the template name, or expect delivery only inside a 24-hour service window. |
| Discord: `HTTP 404` on a webhook that used to work | The webhook was deleted or the channel was removed in Discord. Create a new webhook and paste the new URL. |
| Discord: `HTTP 429` | More than about 30 messages a minute went to one webhook. SiteWatch retries once; use a separate webhook per channel if this recurs. |
| Vitals: `Quota exceeded for quota metric 'Queries'` | The anonymous PageSpeed quota is shared and exhausted. Add a free Google API key under Monitoring Settings. |
| Vitals: INP is always blank | INP only exists as real-user data. Chrome reports it once a site has enough traffic; until then the Total Blocking Time column is the closest lab equivalent. |
| Vitals: a run takes 30+ seconds | Normal — Google renders the page in a real browser. Keep the batch small and the interval at a day. |
| Screenshot: "mShots has queued this page" | Expected on a URL mShots has not seen before — it renders in the background. The next capture normally succeeds; nothing is stored in the meantime. |
| Screenshot: "Could not create the screenshot directory" | `storage/` is not writable by PHP. See [File permissions](#13-file-permissions). |
| Screenshots stop updating | Free services rate limit by IP. Raise the capture interval; "every check" across a fleet will hit the limit. |
| `Composer dependencies are missing` | Run `composer install --no-dev` or upload `vendor/`. |
| Blank page / 500 error | Set `APP_DEBUG=true` temporarily, or read `storage/logs/error.log`. Check PHP version ≥ 8.2 and file permissions. |
| Times are off by several hours | Set the correct timezone under *General Settings*. Storage is UTC. |
| Login blocked: *Too many failed sign-in attempts* | Wait `LOGIN_LOCKOUT_MINUTES` or delete rows from `login_attempts`. |
| Forgot admin password | Another administrator can set a new one under *Team → Users*. Otherwise run `php -r 'echo password_hash("NewPassword123", PASSWORD_DEFAULT);'` and update `users.password_hash` in the database. |
| *You don't have access to this page* | The user's role lacks the permission named on the page. An administrator can change the role under *Team → Roles & Permissions*. |
| Domain registration shows *could not be reached … outbound port 43* | The host blocks classic WHOIS. Domains whose registry offers RDAP (.com, .net, .org, .uk, .au, …) still work over HTTPS; others (.ie, .io, .de, .eu, …) need outbound TCP port 43. |
| Server location shows *Unknown* for many sites | ipinfo.io's anonymous limit was reached. Add a free ipinfo.io token under *Monitoring Settings → Domains & hosting*. |
| Every page redirects to *Updates* after a deploy | The new code needs a database update. An administrator clicks **Update database** there (or runs `php database/migrate.php`). |
| *The database update stopped* | The database user lacks `ALTER`/`CREATE` privileges, or the update was interrupted. Grant the privileges and run it again under *System → Updates* — every step is safe to repeat. |

---

## 19. Users, roles & permissions

*Team → Users* lists everyone who can sign in. Add a user with a name, email address, role and an initial password (use
**Generate** and share it securely — they can change it from their profile). **Deactivate** blocks sign-in and ends their
sessions straight away while keeping their activity history attributed; **Delete** removes the account. Setting a new
password for someone signs them out everywhere.

*Team → Roles & Permissions* lists the roles, the number of users in each, and a permission matrix. Every signed-in user can
open the dashboard, the website list and website details, and edit their own profile. A role grants the rest:

| Area | Permissions |
|------|-------------|
| Websites | Add & edit websites (incl. import, pause/resume, intervals) · Run manual checks · Delete websites |
| Monitoring data | View incidents · View reports (uptime, performance, response times, exports) |
| Domains & hosting | View domain & hosting info · Run domain lookups (refresh stored data, look up any domain) |
| System | Manage notifications · Manage settings · View activity log |
| Team | Manage users · Manage roles |

Built-in roles: **Administrator** (every permission, including ones added in future versions; locked), **Manager** (websites,
checks, incidents, reports, domains and activity — no users, roles, settings or notifications) and **Viewer** (read-only
incidents, reports and domain information). Manager and Viewer can be edited, duplicated or deleted like any custom role.

Guard rails: nobody can grant a permission they do not hold, assign a role with more access than their own, or edit, reset or
delete an account that has more access than they do. You cannot deactivate, delete or change the role of your own account;
the last active Administrator cannot be removed; and a role that is still assigned cannot be deleted. User and role changes
are recorded in the activity log.

---

## 20. Domain & hosting details

Every website details page has **Domain** key figures (age, expiry, hosting company) and *Domain registration* / *Hosting*
panels. *Monitoring → Domains & Hosting* shows all websites in one table (filter by expiring soon, expired or lookup
problems; sort by expiry or age), a breakdown of hosting companies and server countries, and a **Domain lookup** for any
domain — like who.is — without saving anything.

* **Registration** – registrar, registration date and domain age, expiry, last change, registrant (when not privacy-protected),
  name servers, status codes, DNSSEC and the raw record. SiteWatch asks the domain's registry over **RDAP** (JSON over HTTPS;
  servers come from IANA's bootstrap list, cached for a week) and falls back to classic **WHOIS** on TCP port 43 for registries
  without RDAP (e.g. .ie, .io, .de, .eu). The registrable domain is derived from the website host (`www.shop.example.co.uk` →
  `example.co.uk`).
* **Hosting** – IP addresses, reverse DNS, the network (ASN) that owns the address via Team Cymru's DNS service, the hosting
  company, CDN, web server, server city/region/country via ipinfo.io (can be switched off), and which companies host the
  domain's DNS and email. Platform headers (Hostinger, Kinsta, WP Engine, SiteGround, …) are read from a single `HEAD` request
  to the website, SSRF-validated like monitoring requests. When a CDN such as Cloudflare sits in front of a website, its
  location is the CDN edge and the real host usually cannot be identified from outside — SiteWatch says so rather than guessing.
* **Freshness** – details are stored per website and refreshed when older than the *Domain re-check interval* (default 24 hours)
  by `cron/domain-check.php`, automatically when someone opens a website with missing or outdated details, or on demand with
  **Refresh**. Domains expiring within 30 days are flagged. Lookups are rate limited to 30 per user per 10 minutes.

Settings live under *Monitoring Settings → Domains & hosting* (re-check interval, city-level location on/off, optional
ipinfo.io token stored encrypted).

---

## 21. Project structure

```
admin/            Dashboard, websites, incidents, response times, domains & hosting, reports, notifications, settings, activity, users, roles, updates, profile
api/              JSON endpoints (Fetch API) grouped by area: dashboard, websites, incidents, reports, domains, settings, activity, monitoring, notifications, profile, users, roles, system
app/Core/         App container, Config, Database (PDO), Session, Auth, Permission, Migrator, CSRF, Crypto, Lock, Validator, Request/Response, UrlNormalizer, ErrorHandler
app/Domains/      DomainInspector, RdapClient/RdapParser, WhoisClient/WhoisParser, HostingInspector, HostingDetector, DomainName, HttpClient
app/Monitoring/   WebsiteMonitor (Guzzle), SsrfGuard, ErrorDetector, StatusClassifier, SSLChecker, IncidentManager, MonitoringScheduler, UptimeCalculator, MonitorManager, Status
app/Notifications NotificationManager, EmailNotifier (PHPMailer), TelegramNotifier, WhatsAppNotifier, DiscordNotifier, AlertMessage, NotifierInterface
app/Performance/  PageSpeedClient (Core Web Vitals), VitalsResult, ScreenshotCapturer
app/Repositories/ Website, Check, Incident, DailyStats, Settings, User, Role, Domain, Activity, Notification, Heartbeat, Vitals, Screenshot repositories (PDO prepared statements)
app/Services/     DashboardService, WebsiteService (CRUD/import/export), ReportService, DomainService, PerformanceService (vitals & screenshots), TeamService (users & roles), ActivityService, MaintenanceService, ServiceFactory
assets/           app.css (light/dark design system), vanilla JS per page
config/           app.php, database.php (read from .env)
cron/             monitor.php (every minute), cleanup.php (daily), ssl-check.php (daily, optional), domain-check.php (daily, recommended), vitals-check.php (hourly, recommended)
database/         schema.sql, migrate.php (explicit schema upgrade)
includes/         header.php, sidebar.php, footer.php, forbidden.php, website-form.php, report-layout.php
storage/          logs/, cache/, locks/, screenshots/, install.lock (all protected from the web)
tests/            PHPUnit unit tests, fixture server, scenario and lifecycle scripts
install.php       Installation wizard   ·   login.php / logout.php / index.php
```

Scale guidance: the default configuration comfortably handles roughly 50–500 websites depending on intervals and server
resources (500 sites at 5-minute intervals ≈ 100 checks per minute in batches of 15). Concurrency, timeouts, retention and
batch size are configurable; the `MonitorManager` / `WebsiteMonitor` separation allows a queue/worker architecture to be
introduced later without changing the monitoring logic.
