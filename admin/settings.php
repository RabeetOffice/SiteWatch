<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Repositories\WebsiteRepository;

$section = ($_GET['section'] ?? 'general') === 'monitoring' ? 'monitoring' : 'general';
$s = App::settings()->allForDisplay();

$pageTitle = $section === 'monitoring' ? 'Monitoring Settings' : 'General Settings';
$pageSubtitle = $section === 'monitoring' ? 'Intervals, thresholds, confirmation rules and data retention.' : 'Application identity, timezone and display preferences.';
$activeNav = $section === 'monitoring' ? 'monitoring-settings' : 'settings';
$pageScripts = ['settings.js'];
$pageData = ['section' => $section];

require dirname(__DIR__) . '/includes/header.php';

$timezones = DateTimeZone::listIdentifiers();
?>

<div class="row g-4">
    <div class="col-xl-3">
        <div class="sw-card">
            <div class="sw-card-body">
                <nav class="settings-nav">
                    <a href="<?= e(base_url('admin/settings.php')) ?>" class="<?= $section === 'general' ? 'active' : '' ?>"><i class="bi bi-gear"></i> General</a>
                    <a href="<?= e(base_url('admin/settings.php?section=monitoring')) ?>" class="<?= $section === 'monitoring' ? 'active' : '' ?>"><i class="bi bi-sliders"></i> Monitoring</a>
                    <a href="<?= e(base_url('admin/notifications.php')) ?>"><i class="bi bi-bell"></i> Notifications</a>
                    <a href="<?= e(base_url('admin/profile.php')) ?>"><i class="bi bi-person"></i> Profile</a>
                </nav>
            </div>
        </div>
        <?php if ($section === 'monitoring'): ?>
        <div class="sw-card mt-3">
            <div class="sw-card-body fs-13 text-muted">
                <p class="mb-2"><b class="text-body">How confirmation works</b></p>
                <p class="mb-2">A website must fail <b><?= (int) $s['failure_threshold'] ?></b> consecutive checks before an incident is opened and ONE alert is sent. It must succeed <b><?= (int) $s['recovery_threshold'] ?></b> consecutive checks before the incident is resolved and ONE recovery alert is sent.</p>
                <p class="mb-0">Detailed checks older than <b><?= (int) $s['check_retention_days'] ?> days</b> are deleted; daily statistics and incidents are kept forever.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-xl-9">
        <?php if ($section === 'general'): ?>
        <form id="settingsForm" data-section="general" novalidate>
            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Application</h3><p class="sub">Name and address used in the interface and in alert e-mails</p></div></div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="app_name">Application Name</label>
                            <input type="text" class="form-control" id="app_name" name="app_name" maxlength="100" value="<?= e($s['app_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="app_url">Application URL</label>
                            <input type="url" class="form-control" id="app_url" name="app_url" maxlength="255" value="<?= e($s['app_url']) ?>" placeholder="<?= e(base_url()) ?>">
                            <div class="form-text">Used for links in notifications. Leave empty to use <span class="code-inline"><?= e(base_url()) ?></span> from .env.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="app_timezone">Timezone</label>
                            <select class="form-select" id="app_timezone" name="app_timezone">
                                <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"<?= $tz === $s['app_timezone'] ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
                            </select>
                            <div class="form-text">Timestamps are stored in UTC and displayed in this timezone. Current time: <?= e(format_datetime(utc_now()->format('Y-m-d H:i:s'))) ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="dashboard_refresh_seconds">Dashboard refresh</label>
                            <div class="input-group"><input type="number" class="form-control" id="dashboard_refresh_seconds" name="dashboard_refresh_seconds" min="15" max="300" value="<?= (int) $s['dashboard_refresh_seconds'] ?>"><span class="input-group-text">sec</span></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="favicon_provider">Website favicons</label>
                            <select class="form-select" id="favicon_provider" name="favicon_provider">
                                <option value="google"<?= $s['favicon_provider'] === 'google' ? ' selected' : '' ?>>Google favicon service</option>
                                <option value="duckduckgo"<?= $s['favicon_provider'] === 'duckduckgo' ? ' selected' : '' ?>>DuckDuckGo icons</option>
                                <option value="none"<?= $s['favicon_provider'] === 'none' ? ' selected' : '' ?>>Disabled (generic icon)</option>
                            </select>
                            <div class="form-text">Icons load in the browser only; never affects monitoring.</div>
                        </div>
                    </div>
                </div>
                <div class="sw-card-footer d-flex justify-content-end"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Settings</button></div>
            </div>
        </form>

        <div class="sw-card">
            <div class="sw-card-header"><div><h3>Environment</h3><p class="sub">Read-only infrastructure information</p></div></div>
            <div class="sw-card-body">
                <dl class="kv-list mb-0">
                    <dt>PHP</dt><dd><?= e(PHP_VERSION) ?> · cURL <?= e(curl_version()['version'] ?? '?') ?> · <?= e(curl_version()['ssl_version'] ?? '') ?></dd>
                    <dt>Environment</dt><dd><?= e((string) config('app.env')) ?><?= config('app.debug') ? ' <span class="sw-pill tone-warning">debug enabled</span>' : '' ?></dd>
                    <dt>Private targets</dt><dd><?= config('app.monitor.allow_private_targets') ? '<span class="sw-pill tone-warning">allowed (MONITOR_ALLOW_PRIVATE=true)</span>' : '<span class="sw-pill tone-success">blocked (SSRF protection active)</span>' ?></dd>
                    <dt>Storage</dt><dd><?= is_writable((string) config('app.paths.logs')) ? '<span class="text-success">logs writable</span>' : '<span class="text-danger">logs directory not writable</span>' ?> · <?= is_writable((string) config('app.paths.locks')) ? '<span class="text-success">locks writable</span>' : '<span class="text-danger">locks directory not writable</span>' ?></dd>
                    <dt>Cron</dt><dd><span class="mono">* * * * * php <?= e(str_replace('\\', '/', (string) config('app.paths.root'))) ?>/cron/monitor.php</span></dd>
                </dl>
            </div>
        </div>

        <?php else: ?>
        <form id="settingsForm" data-section="monitoring" novalidate>
            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Checks &amp; Confirmation</h3><p class="sub">Defaults for new websites and the false-positive protection rules</p></div></div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="default_check_interval">Default Monitoring Interval</label>
                            <select class="form-select" id="default_check_interval" name="default_check_interval">
                                <?php foreach (WebsiteRepository::INTERVALS as $i): ?><option value="<?= $i ?>"<?= (int) $s['default_check_interval'] === $i ? ' selected' : '' ?>><?= $i ?> minute<?= $i === 1 ? '' : 's' ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="failure_threshold">Default Failure Threshold</label>
                            <div class="input-group"><input type="number" class="form-control" id="failure_threshold" name="failure_threshold" min="1" max="10" value="<?= (int) $s['failure_threshold'] ?>"><span class="input-group-text">failures</span></div>
                            <div class="form-text">Consecutive failed checks before an incident is confirmed.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="recovery_threshold">Default Recovery Threshold</label>
                            <div class="input-group"><input type="number" class="form-control" id="recovery_threshold" name="recovery_threshold" min="1" max="10" value="<?= (int) $s['recovery_threshold'] ?>"><span class="input-group-text">successes</span></div>
                            <div class="form-text">Consecutive successful checks before recovery is confirmed.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>HTTP Requests</h3><p class="sub">Timeouts, redirects and concurrency of the monitoring engine</p></div></div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="request_timeout">Request Timeout</label>
                            <div class="input-group"><input type="number" class="form-control" id="request_timeout" name="request_timeout" min="5" max="120" value="<?= (int) $s['request_timeout'] ?>"><span class="input-group-text">sec</span></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="connect_timeout">Connect Timeout</label>
                            <div class="input-group"><input type="number" class="form-control" id="connect_timeout" name="connect_timeout" min="2" max="60" value="<?= (int) $s['connect_timeout'] ?>"><span class="input-group-text">sec</span></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="max_redirects">Redirect Limit</label>
                            <input type="number" class="form-control" id="max_redirects" name="max_redirects" min="0" max="20" value="<?= (int) $s['max_redirects'] ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="concurrency">Concurrency</label>
                            <div class="input-group"><input type="number" class="form-control" id="concurrency" name="concurrency" min="1" max="50" value="<?= (int) $s['concurrency'] ?>"><span class="input-group-text">sites</span></div>
                            <div class="form-text">Websites checked simultaneously per batch.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Performance Thresholds</h3><p class="sub">Timeouts always take priority over slow classification</p></div></div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="moderate_threshold">Healthy up to</label>
                            <div class="input-group"><input type="number" class="form-control" id="moderate_threshold" name="moderate_threshold" min="100" max="60000" step="100" value="<?= (int) $s['moderate_threshold'] ?>"><span class="input-group-text">ms</span></div>
                            <div class="form-text">Responses below this are shown green.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="slow_threshold">Slow Threshold</label>
                            <div class="input-group"><input type="number" class="form-control" id="slow_threshold" name="slow_threshold" min="200" max="120000" step="100" value="<?= (int) $s['slow_threshold'] ?>"><span class="input-group-text">ms</span></div>
                            <div class="form-text">Status becomes SLOW (warning, no incident).</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="critical_performance_threshold">Critical Performance Threshold</label>
                            <div class="input-group"><input type="number" class="form-control" id="critical_performance_threshold" name="critical_performance_threshold" min="500" max="120000" step="100" value="<?= (int) $s['critical_performance_threshold'] ?>"><span class="input-group-text">ms</span></div>
                            <div class="form-text">Counts as a failure and opens a performance incident.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Engine &amp; SSL</h3></div></div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="heartbeat_threshold_minutes">Engine health threshold</label>
                            <div class="input-group"><input type="number" class="form-control" id="heartbeat_threshold_minutes" name="heartbeat_threshold_minutes" min="1" max="60" value="<?= (int) $s['heartbeat_threshold_minutes'] ?>"><span class="input-group-text">min</span></div>
                            <div class="form-text">Show "Not Running" when the cron has not run for this long.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="ssl_check_interval_hours">SSL re-check interval</label>
                            <div class="input-group"><input type="number" class="form-control" id="ssl_check_interval_hours" name="ssl_check_interval_hours" min="1" max="168" value="<?= (int) $s['ssl_check_interval_hours'] ?>"><span class="input-group-text">hours</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Data Retention</h3><p class="sub">Incidents and daily statistics are always kept</p></div></div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="check_retention_days">Keep Detailed Checks</label>
                            <select class="form-select" id="check_retention_days" name="check_retention_days">
                                <?php foreach ([7, 30, 60, 90] as $d): ?><option value="<?= $d ?>"<?= (int) $s['check_retention_days'] === $d ? ' selected' : '' ?>><?= $d ?> days</option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="activity_retention_days">Keep Activity Logs</label>
                            <div class="input-group"><input type="number" class="form-control" id="activity_retention_days" name="activity_retention_days" min="7" max="3650" value="<?= (int) $s['activity_retention_days'] ?>"><span class="input-group-text">days</span></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="notification_retention_days">Keep Notification Logs</label>
                            <div class="input-group"><input type="number" class="form-control" id="notification_retention_days" name="notification_retention_days" min="7" max="3650" value="<?= (int) $s['notification_retention_days'] ?>"><span class="input-group-text">days</span></div>
                        </div>
                    </div>
                </div>
                <div class="sw-card-footer d-flex justify-content-end"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Monitoring Settings</button></div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
