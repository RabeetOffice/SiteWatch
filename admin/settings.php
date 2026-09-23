<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Repositories\WebsiteRepository;

require_permission('settings.manage');

$section = ($_GET['section'] ?? 'general') === 'monitoring' ? 'monitoring' : 'general';
$s = App::settings()->allForDisplay();

$pageTitle = $section === 'monitoring' ? 'Monitoring Settings' : 'General Settings';
$activeNav = $section === 'monitoring' ? 'monitoring-settings' : 'settings';
$pageScripts = ['settings.js'];
$pageData = ['section' => $section];

require dirname(__DIR__) . '/includes/header.php';

$timezones = DateTimeZone::listIdentifiers();
?>

<nav class="sw-subnav mb-4" aria-label="Settings sections">
    <a href="<?= e(base_url('admin/settings.php')) ?>"<?= $section === 'general' ? ' class="active" aria-current="page"' : '' ?>>General</a>
    <a href="<?= e(base_url('admin/settings.php?section=monitoring')) ?>"<?= $section === 'monitoring' ? ' class="active" aria-current="page"' : '' ?>>Monitoring</a>
    <?php if (can('notifications.manage')): ?><a href="<?= e(base_url('admin/notifications.php')) ?>">Notifications</a><?php endif; ?>
</nav>

<div class="sw-form-col">
<?php if ($section === 'general'): ?>

    <form id="settingsForm" data-section="general" novalidate>
        <section class="sw-card mb-4">
            <div class="sw-card-header"><div><h3>Application</h3><p class="sub">Name, address and timezone used in the interface and in alert emails</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="app_name">Application name</label>
                        <input type="text" class="form-control" id="app_name" name="app_name" maxlength="100" value="<?= e($s['app_name']) ?>" required>
                        <div class="form-text">Shown in the browser tab and in outgoing alerts.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="app_url">Application URL</label>
                        <input type="url" class="form-control" id="app_url" name="app_url" maxlength="255" value="<?= e($s['app_url']) ?>" placeholder="<?= e(base_url()) ?>">
                        <div class="form-text">Used for links inside notifications. Leave empty to use the address from your <span class="code-inline">.env</span> file.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="app_timezone">Timezone</label>
                        <select class="form-select" id="app_timezone" name="app_timezone">
                            <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"<?= $tz === $s['app_timezone'] ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Timestamps are stored in UTC and displayed in this timezone. It is currently <?= e(format_datetime(utc_now()->format('Y-m-d H:i:s'))) ?>.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sw-card mb-4">
            <div class="sw-card-header"><div><h3>Display</h3><p class="sub">How this interface behaves in your browser</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="dashboard_refresh_seconds">Page auto-refresh</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="dashboard_refresh_seconds" name="dashboard_refresh_seconds" min="15" max="300" value="<?= (int) $s['dashboard_refresh_seconds'] ?>">
                            <span class="input-group-text">seconds</span>
                        </div>
                        <div class="form-text">How often open pages fetch new data. This does not change how often websites are checked.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="favicon_provider">Website icons</label>
                        <select class="form-select" id="favicon_provider" name="favicon_provider">
                            <option value="google"<?= $s['favicon_provider'] === 'google' ? ' selected' : '' ?>>Google favicon service</option>
                            <option value="duckduckgo"<?= $s['favicon_provider'] === 'duckduckgo' ? ' selected' : '' ?>>DuckDuckGo icons</option>
                            <option value="none"<?= $s['favicon_provider'] === 'none' ? ' selected' : '' ?>>Disabled (generic icon)</option>
                        </select>
                        <div class="form-text">Icons are loaded by your browser only and never affect monitoring.</div>
                    </div>
                </div>
            </div>
            <div class="sw-card-footer justify-content-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save settings</button>
            </div>
        </section>
    </form>

    <details class="sw-disclosure">
        <summary>Diagnostics <span class="hint">read-only environment information</span></summary>
        <div class="sw-disclosure-body">
            <dl class="kv-list mb-3">
                <dt>PHP</dt><dd><?= e(PHP_VERSION) ?> · cURL <?= e(curl_version()['version'] ?? '?') ?> · <?= e(curl_version()['ssl_version'] ?? '') ?></dd>
                <dt>Environment</dt><dd><?= e((string) config('app.env')) ?><?= config('app.debug') ? ' <span class="sw-pill tone-warning">debug enabled</span>' : '' ?></dd>
                <dt>Private targets</dt><dd><?= config('app.monitor.allow_private_targets') ? '<span class="sw-pill tone-warning">allowed (MONITOR_ALLOW_PRIVATE=true)</span>' : '<span class="sw-pill tone-success">blocked (SSRF protection active)</span>' ?></dd>
                <dt>Storage</dt><dd><?= is_writable((string) config('app.paths.logs')) ? '<span class="text-success">logs writable</span>' : '<span class="text-danger">logs directory not writable</span>' ?> · <?= is_writable((string) config('app.paths.locks')) ? '<span class="text-success">locks writable</span>' : '<span class="text-danger">locks directory not writable</span>' ?></dd>
            </dl>
            <div class="form-label">Monitoring cron command</div>
            <pre class="copy-box mb-3">* * * * * php <?= e(str_replace('\\', '/', (string) config('app.paths.root'))) ?>/cron/monitor.php</pre>
            <div class="form-label">Domain &amp; hosting cron command (daily)</div>
            <pre class="copy-box mb-3">45 4 * * * php <?= e(str_replace('\\', '/', (string) config('app.paths.root'))) ?>/cron/domain-check.php</pre>
            <div class="form-label">Core Web Vitals &amp; screenshots cron command (hourly)</div>
            <pre class="copy-box mb-0">20 * * * * php <?= e(str_replace('\\', '/', (string) config('app.paths.root'))) ?>/cron/vitals-check.php</pre>
        </div>
    </details>

<?php else: ?>

    <form id="settingsForm" data-section="monitoring" novalidate>
        <section class="sw-card mb-4">
            <div class="sw-card-header"><div><h3>Checks &amp; confirmation</h3><p class="sub">Defaults for new websites and the false-positive protection rules</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="default_check_interval">Default check interval</label>
                        <select class="form-select" id="default_check_interval" name="default_check_interval">
                            <?php foreach (WebsiteRepository::INTERVALS as $i): ?><option value="<?= $i ?>"<?= (int) $s['default_check_interval'] === $i ? ' selected' : '' ?>><?= $i ?> minute<?= $i === 1 ? '' : 's' ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Applied to newly added websites. Existing websites keep their own interval.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="failure_threshold">Failures before an incident</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="failure_threshold" name="failure_threshold" min="1" max="10" value="<?= (int) $s['failure_threshold'] ?>">
                            <span class="input-group-text">checks</span>
                        </div>
                        <div class="form-text">A website must fail this many consecutive checks before an incident opens and one alert is sent.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="recovery_threshold">Successes before recovery</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="recovery_threshold" name="recovery_threshold" min="1" max="10" value="<?= (int) $s['recovery_threshold'] ?>">
                            <span class="input-group-text">checks</span>
                        </div>
                        <div class="form-text">It must then succeed this many consecutive checks before the incident resolves and one recovery alert is sent.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sw-card mb-4">
            <div class="sw-card-header"><div><h3>HTTP requests</h3><p class="sub">How the monitoring engine talks to each website</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="request_timeout">Request timeout</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="request_timeout" name="request_timeout" min="5" max="120" value="<?= (int) $s['request_timeout'] ?>">
                            <span class="input-group-text">sec</span>
                        </div>
                        <div class="form-text">Requests past this are counted as failures.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="connect_timeout">Connect timeout</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="connect_timeout" name="connect_timeout" min="2" max="60" value="<?= (int) $s['connect_timeout'] ?>">
                            <span class="input-group-text">sec</span>
                        </div>
                        <div class="form-text">Time allowed to open the connection.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="max_redirects">Redirect limit</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="max_redirects" name="max_redirects" min="0" max="20" value="<?= (int) $s['max_redirects'] ?>">
                            <span class="input-group-text">hops</span>
                        </div>
                        <div class="form-text">Longer chains are reported as a redirect problem.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="concurrency">Concurrency</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="concurrency" name="concurrency" min="1" max="50" value="<?= (int) $s['concurrency'] ?>">
                            <span class="input-group-text">sites</span>
                        </div>
                        <div class="form-text">Websites checked simultaneously per batch.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sw-card mb-4">
            <div class="sw-card-header"><div><h3>Performance thresholds</h3><p class="sub">Where a response stops being healthy. Timeouts always take priority over slow classification.</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="moderate_threshold">Healthy up to</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="moderate_threshold" name="moderate_threshold" min="100" max="60000" step="100" value="<?= (int) $s['moderate_threshold'] ?>">
                            <span class="input-group-text">ms</span>
                        </div>
                        <div class="form-text">Responses below this are shown as healthy.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="slow_threshold">Slow from</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="slow_threshold" name="slow_threshold" min="200" max="120000" step="100" value="<?= (int) $s['slow_threshold'] ?>">
                            <span class="input-group-text">ms</span>
                        </div>
                        <div class="form-text">Status becomes “slow”: a warning, with no incident opened.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="critical_performance_threshold">Critically slow from</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="critical_performance_threshold" name="critical_performance_threshold" min="500" max="120000" step="100" value="<?= (int) $s['critical_performance_threshold'] ?>">
                            <span class="input-group-text">ms</span>
                        </div>
                        <div class="form-text">Counts as a failed check and opens a performance incident.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sw-card mb-4">
            <div class="sw-card-header"><div><h3>Engine &amp; certificates</h3><p class="sub">Freshness of the monitoring engine and how often certificates are inspected</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="heartbeat_threshold_minutes">Engine health threshold</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="heartbeat_threshold_minutes" name="heartbeat_threshold_minutes" min="1" max="60" value="<?= (int) $s['heartbeat_threshold_minutes'] ?>">
                            <span class="input-group-text">min</span>
                        </div>
                        <div class="form-text">If the cron job has not run for this long, SiteWatch warns that monitoring is not running.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ssl_check_interval_hours">SSL re-check interval</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="ssl_check_interval_hours" name="ssl_check_interval_hours" min="1" max="168" value="<?= (int) $s['ssl_check_interval_hours'] ?>">
                            <span class="input-group-text">hours</span>
                        </div>
                        <div class="form-text">Certificates are re-inspected no more often than this.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sw-card mb-4">
            <div class="sw-card-header"><div><h3>Domains &amp; hosting</h3><p class="sub">Registration (RDAP / WHOIS) lookups and server location</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="domain_check_interval_hours">Domain re-check interval</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="domain_check_interval_hours" name="domain_check_interval_hours" min="1" max="720" value="<?= (int) $s['domain_check_interval_hours'] ?>">
                            <span class="input-group-text">hours</span>
                        </div>
                        <div class="form-text">Details older than this are refreshed by <span class="code-inline">cron/domain-check.php</span>, or when someone opens them.</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="ipinfo_token">ipinfo.io token <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="password" class="form-control" id="ipinfo_token" name="ipinfo_token" maxlength="64" autocomplete="off"
                               placeholder="<?= $s['ipinfo_token_set'] ? '•••••••••• (saved — leave blank to keep)' : 'Not set — the free anonymous limit is used' ?>">
                        <div class="form-text">Server cities come from ipinfo.io. A free ipinfo.io token raises the lookup limit and is stored encrypted.</div>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="domain_geo_lookup" name="domain_geo_lookup" value="1"<?= $s['domain_geo_lookup'] ? ' checked' : '' ?>>
                            <label class="form-check-label" for="domain_geo_lookup">Look up server city and region</label>
                        </div>
                        <div class="form-text">When off, only the country of the hosting network is shown and no server addresses are sent to ipinfo.io.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sw-card mb-4">
            <div class="sw-card-header"><div><h3>Data retention</h3><p class="sub">Incidents and daily statistics are always kept</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="check_retention_days">Keep detailed checks</label>
                        <select class="form-select" id="check_retention_days" name="check_retention_days">
                            <?php foreach ([7, 30, 60, 90] as $d): ?><option value="<?= $d ?>"<?= (int) $s['check_retention_days'] === $d ? ' selected' : '' ?>><?= $d ?> days</option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Older individual check rows are deleted. Uptime history is unaffected.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="activity_retention_days">Keep activity log</label>
                        <select class="form-select" id="activity_retention_days" name="activity_retention_days">
                            <?php foreach (\App\Repositories\SettingsRepository::ACTIVITY_RETENTION_CHOICES as $d): ?><option value="<?= $d ?>"<?= (int) $s['activity_retention_days'] === $d ? ' selected' : '' ?>><?= $d === 0 ? 'Unlimited' : $d . ' days' ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Older entries are deleted every day, and right away when you shorten the period.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="notification_retention_days">Keep delivery history</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="notification_retention_days" name="notification_retention_days" min="7" max="3650" value="<?= (int) $s['notification_retention_days'] ?>">
                            <span class="input-group-text">days</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sw-card-footer justify-content-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save monitoring settings</button>
            </div>
        </section>
    </form>

    <form id="performanceForm" data-section="performance" novalidate>
        <section class="sw-card mb-4">
            <div class="sw-card-header">
                <div><h3>Core Web Vitals</h3><p class="sub">LCP, CLS, INP and TTFB through Google PageSpeed Insights</p></div>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="vitals_enabled" name="vitals_enabled" value="1"<?= $s['vitals_enabled'] ? ' checked' : '' ?>>
                    <label class="form-check-label fw-600" for="vitals_enabled">Enabled</label>
                </div>
            </div>
            <div class="sw-card-body">
                <p class="text-muted fs-13 mb-3">
                    LCP, CLS and INP describe what a browser does while rendering a page, so they cannot be measured
                    from PHP. SiteWatch asks Google to run them instead. Each run returns <b>lab</b> results from a
                    Lighthouse render, and <b>field</b> results from real Chrome users over the last 28 days — the
                    field set is where INP comes from, and it only appears once a site has enough traffic.
                    <b>Time to first byte is measured separately on every single check</b> and does not depend on this.
                </p>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="pagespeed_api_key">Google API key</label>
                        <input type="password" class="form-control" id="pagespeed_api_key" name="pagespeed_api_key" maxlength="60" autocomplete="off"
                               placeholder="<?= $s['pagespeed_api_key_set'] ? '•••••••••• (saved — leave blank to keep)' : 'AIza…' ?>">
                        <div class="form-text">
                            Required in practice. PageSpeed can be called without a key, but that quota is a pool shared
                            by every anonymous caller and is nearly always exhausted. A key is free and needs no billing:
                            create a project in the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud console</a>,
                            enable the <span class="code-inline">PageSpeed Insights API</span> and create an API key.
                            Stored encrypted.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="vitals_strategies">Measure</label>
                        <select class="form-select" id="vitals_strategies" name="vitals_strategies">
                            <option value="both"<?= $s['vitals_strategies'] === 'both' ? ' selected' : '' ?>>Mobile and desktop</option>
                            <option value="mobile"<?= $s['vitals_strategies'] === 'mobile' ? ' selected' : '' ?>>Mobile only</option>
                            <option value="desktop"<?= $s['vitals_strategies'] === 'desktop' ? ' selected' : '' ?>>Desktop only</option>
                        </select>
                        <div class="form-text">Each one is a separate request.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="vitals_interval_hours">Re-check interval</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="vitals_interval_hours" name="vitals_interval_hours" min="1" max="720" value="<?= (int) $s['vitals_interval_hours'] ?>">
                            <span class="input-group-text">hours</span>
                        </div>
                        <div class="form-text">A Lighthouse run takes 10–40 seconds per page. Daily is plenty for spotting regressions.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="vitals_retention_days">Keep vitals history</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="vitals_retention_days" name="vitals_retention_days" min="7" max="3650" value="<?= (int) $s['vitals_retention_days'] ?>">
                            <span class="input-group-text">days</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sw-card mb-4">
            <div class="sw-card-header">
                <div><h3>Screenshots</h3><p class="sub">A picture of each website, refreshed on a schedule</p></div>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="screenshot_enabled" name="screenshot_enabled" value="1"<?= $s['screenshot_enabled'] ? ' checked' : '' ?>>
                    <label class="form-check-label fw-600" for="screenshot_enabled">Enabled</label>
                </div>
            </div>
            <div class="sw-card-body">
                <p class="text-muted fs-13 mb-3">
                    Rendering a page needs a real browser, which this server does not have, so screenshots are produced
                    by an external service. That service is sent the address of each monitored website. Images are kept
                    in <span class="code-inline">storage/screenshots</span>, outside the web root, and are only served to
                    signed-in users.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="screenshot_provider">Provider</label>
                        <select class="form-select" id="screenshot_provider" name="screenshot_provider">
                            <option value="mshots"<?= $s['screenshot_provider'] === 'mshots' ? ' selected' : '' ?>>WordPress mShots — free, no key</option>
                            <option value="thumio"<?= $s['screenshot_provider'] === 'thumio' ? ' selected' : '' ?>>thum.io — free, no key</option>
                            <option value="pagespeed"<?= $s['screenshot_provider'] === 'pagespeed' ? ' selected' : '' ?>>PageSpeed render — no extra request</option>
                        </select>
                        <div class="form-text">
                            mShots renders in the background, so a brand new website may need two attempts.
                            The PageSpeed option reuses the image from a Core Web Vitals run, so it needs vitals
                            enabled and is only ever as fresh as that schedule.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="screenshot_interval_minutes">Capture every</label>
                        <select class="form-select" id="screenshot_interval_minutes" name="screenshot_interval_minutes">
                            <?php
                            $intervals = [0 => 'Every check (see the warning below)', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 180 => '3 hours', 360 => '6 hours', 720 => '12 hours', 1440 => '24 hours'];
                            $current = (int) $s['screenshot_interval_minutes'];
                            foreach ($intervals as $minutes => $label): ?>
                                <option value="<?= $minutes ?>"<?= $current === $minutes ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <b>Every check</b> means one request to the screenshot service per website per interval —
                            with 30 websites on 5-minute checks that is roughly 8,600 requests a day, which free
                            services rate limit. Prefer an hour or more, and use <b>Capture now</b> on a website when
                            you want to see it this second.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="screenshot_retention_days">Keep screenshots</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="screenshot_retention_days" name="screenshot_retention_days" min="1" max="3650" value="<?= (int) $s['screenshot_retention_days'] ?>">
                            <span class="input-group-text">days</span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="screenshot_keep_per_website">Keep at most</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="screenshot_keep_per_website" name="screenshot_keep_per_website" min="1" max="500" value="<?= (int) $s['screenshot_keep_per_website'] ?>">
                            <span class="input-group-text">per website</span>
                        </div>
                        <div class="form-text">Whichever limit is reached first. Older images are deleted from disk by the cleanup job.</div>
                    </div>
                </div>
            </div>
            <div class="sw-card-footer justify-content-between">
                <span class="text-muted fs-13">Both jobs run from <span class="code-inline">cron/vitals-check.php</span>.</span>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save performance settings</button>
            </div>
        </section>
    </form>

<?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
