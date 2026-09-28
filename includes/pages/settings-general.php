<?php

declare(strict_types=1);

/**
 * Settings → General (settings.js). Uses $s (settings for display).
 */

$timezones = DateTimeZone::listIdentifiers();
?>
<form id="settingsForm" data-section="general" novalidate>
    <div class="sw-form-grid mb-3">
        <section class="sw-card">
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

        <section class="sw-card">
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
        </section>
    </div>
    <div class="sw-form-actions">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save general settings</button>
    </div>
    </form>

    <details class="sw-disclosure mt-3">
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
            <pre class="copy-box mb-3">20 * * * * php <?= e(str_replace('\\', '/', (string) config('app.paths.root'))) ?>/cron/vitals-check.php</pre>
            <div class="form-label">Country availability cron command (hourly)</div>
            <pre class="copy-box mb-0">40 * * * * php <?= e(str_replace('\\', '/', (string) config('app.paths.root'))) ?>/cron/country-check.php</pre>
        </div>
    </details>
