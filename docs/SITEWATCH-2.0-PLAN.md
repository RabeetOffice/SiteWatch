# SiteWatch 2.0 — Plan

Status: **built as 2.0.0 on 28 Sep 2026** (database schema 13) · previous release 1.14.0 (schema 12)

> **What was built, and where it differs from this plan**
> - Decisions taken with the proposed defaults: the Performance report became Performance → Response time (24 h / 7 d / 30 d /
>   90 d / custom, export, print); the Dashboard website table was removed (tiles open the Websites page filtered); Inter; the
>   15 countries in 9.2; extras A, B, C and G; Web Push included.
> - Page lifecycle (section 5.2): instead of rewriting 20 page scripts, `app.js` records what each page starts (listeners
>   added while it boots, intervals, polls, charts) and stops it on navigation. Page scripts are unchanged.
> - Data in the page (5.4) is done for Response time and the Uptime report; the Dashboard already had it. Other pages still
>   load their first data with one request.
> - Notifications while open (8, level 1) are only used on devices without Web Push, so nothing arrives twice.
> - Not done in 2.0: Websites page status tiles and saved views (H), client PDF reports, digests and status pages (D–F),
>   the 300 ms latency test and a Lighthouse run. Install and Web Push must be checked in Chrome/Edge: the in-app test
>   browser cannot register service workers.

SiteWatch 2.0 is a visual and usability release. The monitoring engine, data and permissions stay as they are.
What changes is how the app looks, how fast it feels, how you move around it, and where it lives: it becomes an
installable desktop app that can alert you even when it is closed. It also adds one new feature: **country
availability checks**, which show where in the world a website cannot be reached.

---

## 1. Goals

1. **One place for each thing.** Remove duplicate pages and duplicate links. The sidebar goes from 17 links to 9.
2. **Feels instant.** Page changes swap only the content (no full reload, no white flash), with data already in
   the page and a thin progress bar while loading.
3. **Looks professional and consistent.** One set of page headers, filter bars, summary tiles, tables and empty
   states used everywhere, in light and dark mode.
4. **Installable app.** It installs on Windows and macOS from Chrome/Edge (and Safari on macOS as a Dock app), and
   has its own window, icon, start-up splash and incident badge.
5. **Desktop notifications.** Incidents, recoveries and WordPress fatal errors show as system notifications, both
   while SiteWatch is open and when it is closed (Web Push).
6. **Country availability.** A free check from about 12–15 countries showing whether each website loads, is slow,
   fails, or looks blocked.

Not in scope: rewriting in React, changing the database engine, multisite support (removed on purpose in 1.x).

---

## 2. What changes: overview

| Area | 1.14 today | 2.0 |
|---|---|---|
| Sidebar | 17 links, 5 groups | 9 links, 4 groups |
| Page change | Full reload, then a second API call | Content swap + data already in the page |
| First load | Blank page, then skeletons | Short branded splash (first load per session only) |
| Performance pages | Response Times + Performance report + Core Web Vitals | One **Performance** page with tabs |
| Reports | Uptime + Performance (same template) | One **Uptime report** (print/export) |
| Team | Users + Roles & Permissions | One **Team** page with tabs |
| Settings | General + Monitoring + Notifications (3 links, 1 page) | One **Settings** link with tabs |
| Website details | 15 cards in one scroll (~3,900 px) | 6 tabs, Overview first |
| Dashboard | Includes a copy of the Websites table | Summary only, links out |
| "Not running" warning | Banner on every page **and** in the top bar | Top bar only (clickable) |
| Install as app | No | Yes (PWA) |
| Desktop notifications | No | Yes (open app + Web Push) |
| Country checks | No | Yes |

---

## 3. Navigation (information architecture)

```
Dashboard
MONITORING
  Websites            Add website / Import are buttons on this page
  Incidents
  Performance         Tabs: Response time · Core Web Vitals · Countries
  Domains & Hosting
REPORTS
  Uptime report
ADMIN
  Team                Tabs: Users · Roles & permissions
  Settings            Tabs: General · Monitoring · Notifications
  Activity log
```

- **Updates** stays reachable from the version link in the sidebar footer (admins only), as it is today.
- **Old URLs redirect**: `response-times.php`, `performance.php`, `web-vitals.php`, `users.php`, `roles.php`,
  `notifications.php`, `website-add.php` and `settings.php?section=…` redirect (301) to the matching page and tab,
  so bookmarks keep working.
- **Tabs follow permissions**: a tab the role cannot open is hidden, and a page with no visible tabs is removed
  from the sidebar (same rule the sidebar already uses).
- **Tabs are real URLs** (`performance.php?tab=vitals`), so they can be bookmarked and shared, and Back works.
- **Command palette (Ctrl/⌘ + K)**: type a website name or domain, or a page name, and jump to it. With 30+
  websites this is the fastest way around the app.
- **Breadcrumb** on detail pages: `Websites › Ken Heather › Checks`.

### Duplicates removed

| Removed | Why | Replacement |
|---|---|---|
| Response Times page | Same data as the Performance report | Performance → Response time |
| Performance report | Same template as Uptime report | Performance → Response time (date range, export, print) |
| Add Website link | Already a button on Websites and Dashboard | Button only |
| Monitoring Settings link | Same page as General Settings | Settings → Monitoring tab |
| Notifications link | Already a tab in Settings | Settings → Notifications tab |
| Updates link | Already in the sidebar footer | Footer version link |
| Dashboard website table | Paged copy of Websites | "View all websites" link |
| Page "not running" banner | Same as the top-bar indicator | Clickable top-bar indicator |

---

## 4. Visual design

### 4.1 Principles (the "no AI slop" rules)

- **One accent colour.** Orange (`--sw-primary`) is only for the primary action on a page, the active nav item
  and focus rings. Status colours (green/amber/red/grey) are only for status.
- **No decoration for its own sake**: no gradient text, glass/blur panels, glowing shadows, emoji icons,
  background blobs or stock illustrations. Icons come from one set (Bootstrap Icons) at one weight.
- **Motion has a job**: it shows that something changed or is loading. 150–200 ms, ease-out, no bouncing. Everything
  respects `prefers-reduced-motion`.
- **Numbers line up**: tabular figures for all metrics, right-aligned numeric columns, units in a lighter weight
  (`2.74 s`, `73.91 %`).
- **Real content, real empty states**: when there is no data, say so in one line and offer the next step; never
  draw empty charts with "0 ms" axes.
- **Plain language**: sentence case everywhere ("Uptime report", not "Uptime Reports"), short subtitles, no filler.

### 4.2 Design tokens (refined, not replaced)

- **Type**: Inter (self-hosted, variable, ~100 KB, cached for a year), falling back to the system font. Scale: 12 /
  13 / 14 / 16 / 20 / 24 px. Page title 20 px semibold, card title 14 px semibold.
- **Spacing**: 4 px base, using 4 / 8 / 12 / 16 / 24 / 32. Cards 16 px padding (20 px from 1440 px up).
- **Radius**: 6 (controls), 10 (cards), 999 (pills). Already in `app.css`; applied consistently.
- **Elevation**: cards use a 1 px border, not shadows. Only menus, dialogs and toasts get a shadow.
- **Width**: content fills the width up to 1600 px. Settings forms use a 2-column grid instead of stopping at
  640 px.
- Light and dark both designed and checked. Contrast stays at WCAG AA or better.

### 4.3 Shared components (one of each, used everywhere)

| Component | Rules |
|---|---|
| **Page header** | Title · optional one-line subtitle · actions on the right (max 1 primary + 2 secondary, extras in a "⋯" menu). Same height on every page. |
| **Tabs** | Underline tabs right under the header; count badges allowed (e.g. `Errors 3`). |
| **Filter bar** | Search → selects → date range → presets · spacer · Export / Print. One row on desktop, wraps as a group on mobile. Buttons never look disabled unless they are. |
| **Summary strip** | Always 4 tiles (6 only on Dashboard). Label, value, one-line note. Tiles that filter the table are clickable everywhere, not only on Domains. |
| **Table** | Website cell = favicon + name + domain, client as a small tag under it. Numbers right-aligned. Row actions in the last column. Sticky header. Long text gets an ellipsis plus a tooltip. |
| **Empty state** | Icon, one sentence, one action. Used for tables, charts and tabs. |
| **Loading** | Skeletons with the same shape as the final content (no layout shift). Buttons show an inline spinner. |
| **Toasts** | Bottom-right, max 3, auto-dismiss 5 s, with an undo action where possible. |

---

## 5. Speed ("feels like React")

Measured locally: the server returns pages in ~20 ms. The slowness you feel comes from the full reload and the
second API request each page makes after it loads. On Hostinger each round trip is slower, so it adds up.

1. **Content swap navigation** (`assets/js/app.js`, ~150 lines, no framework): internal links are fetched in the
   background. Only `<main>` and the title are replaced, the sidebar and top bar stay, the URL updates with
   `history.pushState`, and Back/Forward work. Forms, downloads, CSV export and logout still use normal page loads.
2. **Page lifecycle**: each of the 20 page scripts becomes `SW.page('websites', { mount, unmount })`, so timers
   (auto-refresh, `SW.poll`, the progress ticker in `websites.js`) and listeners are cleaned up when you leave a
   page.
3. **Hover prefetch**: resting on a link for 65 ms starts loading the page.
4. **No waterfall**: the first page of data is rendered into `$pageData`, so tables and tiles show data straight
   away. The API is used only for refreshes, filters and paging.
5. **Stale-while-revalidate**: recent API results are kept in memory, so returning to a page shows data at once
   and then refreshes quietly.
6. **Assets**: Bootstrap, icons, Inter and Chart.js are self-hosted with 1-year `immutable` cache headers
   (filenames are already versioned), text is compressed, and Chart.js loads only on the first page that needs it.
   Check that OPcache is on at Hostinger.
7. **Target**: page change under 150 ms perceived on Hostinger. Verify with 300 ms artificial latency before
   release.

---

## 6. Splash screen and loading animation

- **When**: only the first page load of a browser session, and each time the installed app is launched. Never on
  in-app page changes.
- **What**: the SiteWatch mark on the theme background. Its pulse line draws itself once (SVG stroke animation,
  ~600 ms), then the splash fades out as soon as the page is ready.
- **Rules**: shown for at least 400 ms and at most 1.2 s. It never delays a page that is ready. Skipped under
  `prefers-reduced-motion`. Inline CSS/SVG in `header.php`, so it paints before any other file loads.
- **Installed app**: the OS start-up splash uses the manifest `background_color` and icon, then hands over to the
  same animation, so there is no colour jump.
- **In-app loading**: a 2 px progress bar under the top bar during page swaps, plus skeletons. No full-screen
  spinners.

---

## 7. Installable desktop app (PWA)

Works on Chrome and Edge (Windows/macOS/Linux) and Safari 17+ on macOS ("Add to Dock"). Requires HTTPS, which
production already has. `localhost` also counts as secure for testing.

- **`manifest.webmanifest`**: name "SiteWatch", `display: standalone`, theme and background colours, icons 192 /
  512 / maskable (generated from `sitewatch-mark.svg`), `start_url: admin/dashboard.php`, and shortcuts to
  Websites, Incidents and Add website (right-click the taskbar/Dock icon).
- **Service worker `sw.js`** at the app root (scope `/sitewatch/` locally, `/` on production):
  - caches the app shell (CSS, JS, fonts, icons) for instant start-up;
  - loads pages and API data from the network (never serves stale monitoring data as current);
  - shows a small offline page ("You're offline — last update 14:32");
  - clears its caches on logout.
- **Install button**: "Install app" in the top-bar menu and on the Profile page. It uses the browser's install
  prompt and shows short instructions on Safari.
- **App badge**: the taskbar/Dock icon shows the number of open incidents (Badging API), updated by the existing
  status poll.

---

## 8. Desktop notifications

Two levels, both opt-in per device from **Profile → Notifications on this device**:

**Level 1 — while SiteWatch is open** (any tab or the installed app). The existing status poll shows a system
notification for: new incident, recovery, SSL expiring or expired, WordPress fatal error, and country block
detected. No server changes are needed.

**Level 2 — Web Push, when SiteWatch is closed.**
- New channel `App\Notifications\WebPushNotifier` implementing the existing `NotifierInterface`, so it follows the
  same alert rules, confirmation and cooldowns as email, Telegram, WhatsApp and Discord.
- VAPID key pair is generated once during the 2.0 database update. The private key is stored encrypted with
  `App\Core\Crypto`.
- New table `push_subscriptions` (user, endpoint, keys, device label, last used). Dead endpoints (HTTP 404/410)
  are removed automatically.
- Per-user choices: which events, and optionally which clients. Only users whose role can view incidents receive
  them.
- Clicking a notification opens the incident or the website in the app.
- "Send test notification" button, and delivery logged in the existing Delivery history.
- Library: `minishlink/web-push` (MIT, PHP 8.1+, openssl; gmp or bcmath for speed). Check that gmp/bcmath is
  enabled on Hostinger.

---

## 9. New feature: country availability checks

**Question it answers:** *Can people in country X open this website right now? If not, is the site down there,
slow, geo-blocked by our own firewall/CDN, or blocked by the country's network?*

### 9.1 Where the checks run (free)

| Provider | Use | Free limit |
|---|---|---|
| **Globalping** (jsDelivr, open source) | Primary: HTTP and DNS tests from probes in a chosen country, many of them on home/ISP networks | 250 tests/hour without an account, **500/hour with a free token** (one test = one probe) |
| **check-host.net** | Fallback for countries Globalping has no probe in right now | Free public API. No published limit, so used sparingly |

Both are called from PHP with Guzzle, which is already installed. The Globalping token (optional) goes in
Settings → Monitoring, stored encrypted.

### 9.2 Countries (15; each can be switched off)

Where your clients' readers are: **United States, United Kingdom, Ireland, Australia, Canada**
Major regions for coverage: **Germany, India, Pakistan, UAE, Singapore, Brazil, South Africa**
Known for network blocking (best-effort, depends on probe availability): **Turkey, Russia, China**

If a country has no probe available, it shows **"No probe"**, never "blocked".

### 9.3 How a check works

For each website and country:
1. **DNS test** from a probe: does the domain resolve, and to the same addresses as everywhere else?
2. **HTTP test** (GET, 15 s timeout): status code, time, TLS result, final URL.
3. If it fails, **re-check from a second probe on a different network** before reporting, the same false-positive
   protection the main monitor uses.

| Result | Meaning |
|---|---|
| **Reachable** | 2xx/3xx within the slow threshold |
| **Slow** | Loaded, but slower than the slow threshold |
| **Geo-blocked by site** | 403/451 or a firewall/CDN challenge only in this country (for example Wordfence or Cloudflare country rules). Usually our own setting. |
| **Possibly blocked by network** | DNS answer differs only there (wrong IP, NXDOMAIN, known block-page IP), or HTTP 451, or a connection reset/redirect to a block page on two networks |
| **Unreachable** | Timeouts or connection failures from two networks while other countries are fine |
| **Down everywhere** | Fails in most countries. This is a normal outage, handled by the main monitor, not flagged per country |

Results are an indication, not proof. The page says so in one line.

### 9.4 Schedule and budget

- 34 websites × 15 countries = 510 tests per full sweep, just over the free hourly limit.
- **Daily sweep** by a new `cron/country-check.php`, which spreads the tests across the day and stays under 400
  tests/hour.
- **"Check from all countries"** button per website for on-demand runs (15–30 tests).
- Stops automatically and resumes next hour if the provider returns a rate-limit response.

### 9.5 Where it shows

- **Performance → Countries**: a table of websites × countries with coloured cells, a summary strip (Reachable
  everywhere · Slow somewhere · Blocked somewhere · Not checked), and filters by client and result.
- **Website details → Overview**: a small world strip of 15 flags/codes with status, plus the "Check from all
  countries" button.
- **Alerts**: a new alert rule, "Website became unreachable in a country". It fires only after two sweeps in a row
  agree, and goes through all channels including desktop notifications.

### 9.6 Data

- `country_checks` (website, country, probe network/ASN, DNS result, HTTP status, time ms, classification,
  checked_at). Kept 90 days by the existing cleanup cron.
- Settings: enabled countries, token, sweep on/off, alert on/off.

---

## 10. Page-by-page

| Page | 2.0 changes |
|---|---|
| **Dashboard** | Summary strip (6), Needs attention, WordPress plugin, Trends, Recent activity. The website table is removed ("View all websites" link). Adds a "Countries with problems" line when any exist. |
| **Websites** | Same table, cleaner filter bar, clickable status tiles, saved filters remembered per user, and a bulk-action bar that slides in when rows are selected. |
| **Website details** | Tabs: **Overview** (tiles on one row, response chart, uptime timeline, latest incidents, country strip) · **Checks** · **WordPress** (existing sub-tabs) · **Performance** (Web Vitals + screenshot) · **Domain & hosting** · **Configuration**. Remembers the last tab. |
| **Incidents** | Shared filter bar. Opening an incident uses a side panel instead of an inline expand, so the list stays in place. |
| **Performance** | Tabs: Response time (24h / 7d / 30d / 90d / custom, export, print), Core Web Vitals (mobile/desktop), Countries (new). |
| **Domains & Hosting** | Shared header, filter bar and tiles. Lookup tool moves to a "Look up a domain" button + dialog, so the table comes first. |
| **Uptime report** | Uptime-only columns, print layout kept, export kept. |
| **Team** | Users and Roles tabs. |
| **Settings** | General · Monitoring · Notifications tabs, 2-column forms, a sticky "Save changes" bar that appears only when something changed. Countries settings go in Monitoring. |
| **Profile** | Adds "Install app" and "Notifications on this device". |
| **Login** | Same splash visual, cleaner card. |

---

## 11. Other things we can change in 2.0 (pick what you want)

| # | Idea | Why | Size |
|---|---|---|---|
| A | Command palette (Ctrl/⌘ + K) | Fastest way to reach any of 30+ sites | S |
| B | Keyboard shortcuts (`g w` websites, `g i` incidents, `/` search, `?` help) | Power use | S |
| C | App badge with open-incident count | You see problems without opening the app | S |
| D | Client-branded PDF uptime report | Send monthly reports to clients directly | M |
| E | Weekly email/desktop digest (uptime, slowest sites, expiring domains/SSL) | One summary instead of checking daily | M |
| F | Public status page per client (read-only link) | Clients check status themselves | M |
| G | Compact / comfortable table density toggle | More rows on a laptop screen | S |
| H | Saved filter views ("UK – problems only") | Repeat views in one click | S |

A, B, C and G are recommended for 2.0. D–F can follow in 2.1.

---

## 12. Technical changes

- **Database**: one Migrator step, schema 12 → 13, `'release' => '2.0.0'`. Adds `push_subscriptions` and
  `country_checks`, new settings keys (VAPID keys, country settings, alert rule), and user preferences for
  notifications. Following the existing workflow: `database/schema.sql` updated, `Migrator::STEPS` described,
  admins click "Update database" after deploy.
- **New files**: `manifest.webmanifest`, `sw.js`, `offline.html`, `assets/images/icons/*`, `assets/fonts/inter/*`,
  `assets/vendor/*` (self-hosted Bootstrap/icons/Chart.js), `includes/page-header.php`, `includes/tabs.php`,
  `app/Countries/*` (GlobalpingClient, CheckHostClient, CountryClassifier), `app/Services/CountryCheckService.php`,
  `app/Repositories/CountryCheckRepository.php`, `app/Notifications/WebPushNotifier.php`, `api/push/*`,
  `api/countries/*`, `cron/country-check.php`.
- **Merged pages**: `admin/performance.php` (tabs), `admin/team.php`, `admin/settings.php` (tabs). Old files become
  redirects.
- **Composer**: add `minishlink/web-push`.
- **Security**: service worker never caches API responses or anything behind auth beyond the shell. Push payloads
  contain no secrets (just a title and a link). The CSP nonce model is kept, and `manifest-src`/`worker-src` are
  added if needed. SSRF guard is untouched, since country checks run on the providers' probes, not our server.
- **Hostinger**: add the `cron/country-check.php` cron (`/opt/alt/php83/usr/bin/php`). Confirm the gmp or bcmath
  extension for Web Push.

---

## 13. Phases

| Phase | Work | Size |
|---|---|---|
| **0 — Foundation** | Tokens, Inter, self-hosted assets, shared components, page lifecycle, content-swap navigation, prefetch, progress bar | L |
| **1 — Navigation clean-up** | New sidebar, merged pages with tabs, redirects, command palette | M |
| **2 — Page redesigns** | Dashboard, Websites, Website details tabs, Incidents side panel, Domains, Settings | L |
| **3 — App feel** | Splash, PWA manifest + service worker + install, app badge, Level 1 notifications | M |
| **4 — Web Push** | VAPID, subscriptions, WebPushNotifier, profile settings, test button | M |
| **5 — Country checks** | Providers, classifier, cron, Countries tab, website strip, alert rule | L |
| **6 — Release 2.0.0** | Migration step 13, Release notes + CHANGELOG, docs, full test pass, deploy + DB update on Hostinger | S |

Each phase ships working on its own, so work can pause after any phase without leaving the app broken.

---

## 14. Testing

- `vendor/bin/phpunit`: new unit tests for CountryClassifier (fixtures of real DNS/HTTP results), WebPushNotifier
  payloads, redirects of old URLs, tab permissions and the migration step. `ReleaseTest` stays green.
- `php tests/scenarios.php` and `php tests/lifecycle.php` still pass.
- Browser: every page at 1440 px and 375 px, light and dark, a non-admin role (hidden tabs/links), Back/Forward
  after content swaps, no leftover timers after navigating (check with devtools), 300 ms latency test.
- PWA: install on Chrome and Edge (Windows), Safari "Add to Dock" (macOS), offline page, badge, push delivered
  with the app closed.
- Lighthouse: Performance ≥ 90 and Accessibility ≥ 95 on Dashboard and Websites.

---

## 15. Decisions needed

1. **Performance report**: fold into Performance → Response time (proposed), or keep as a second report type?
2. **Dashboard website table**: remove (proposed), or keep a short "5 slowest / at-risk" list?
3. **Font**: Inter (proposed) or keep the system font?
4. **Countries**: is the list of 15 in 9.2 right? Replace any with countries your readers come from.
5. **Extras from section 11**: which of A–H go into 2.0?
6. **Web Push**: needed in 2.0, or is Level 1 (notifications while the app is open) enough for now?
