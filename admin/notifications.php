<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;

$s = App::settings()->allForDisplay();

$pageTitle = 'Notifications';
$activeNav = 'notifications';
$pageScripts = ['settings.js'];
$pageData = ['section' => 'notifications'];

require dirname(__DIR__) . '/includes/header.php';

$alertRules = [
    'alert_down'     => ['Website down', 'Outages, connection failures, DNS errors, redirect problems and maintenance mode'],
    'alert_critical' => ['WordPress critical error', 'The WordPress “critical error” page or an exposed PHP fatal error'],
    'alert_database' => ['Database error', '“Error establishing a database connection” and related failures'],
    'alert_http'     => ['HTTP 5xx / 4xx', 'Server errors (500, 502, 503, 504) and error responses from the homepage'],
    'alert_timeout'  => ['Timeout', 'Requests that exceed the configured request timeout'],
    'alert_ssl'      => ['SSL certificate', 'Certificate errors and expiry warnings (30, 14 and 7 days, and expired)'],
    'alert_slow'     => ['Critically slow', 'Responses over the critical performance threshold'],
    'alert_recovery' => ['Recovery', 'One notification when a website comes back online'],
];
?>

<div class="row g-4">
    <div class="col-xxl-7">
        <form id="emailForm" data-section="email" novalidate>
            <section class="sw-card mb-4">
                <div class="sw-card-header">
                    <div><h3>Email</h3><p class="sub">Alerts delivered through your SMTP server</p></div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="email_enabled" name="email_enabled" value="1"<?= $s['email_enabled'] ? ' checked' : '' ?>>
                        <label class="form-check-label fw-600" for="email_enabled">Enabled</label>
                    </div>
                </div>
                <div class="sw-card-body">
                    <p class="text-muted fs-13 mb-3">
                        Enter the outgoing mail details from your hosting or mail provider. Port <b>587</b> normally uses
                        STARTTLS, port <b>465</b> uses SSL/TLS. Save first, then send a test email to confirm delivery.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="smtp_host">SMTP host</label>
                            <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?= e($s['smtp_host']) ?>" placeholder="smtp.example.com" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="smtp_port">Port</label>
                            <input type="number" class="form-control" id="smtp_port" name="smtp_port" min="1" max="65535" value="<?= (int) $s['smtp_port'] ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="smtp_encryption">Encryption</label>
                            <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                <option value="tls"<?= $s['smtp_encryption'] === 'tls' ? ' selected' : '' ?>>STARTTLS (587)</option>
                                <option value="ssl"<?= $s['smtp_encryption'] === 'ssl' ? ' selected' : '' ?>>SSL/TLS (465)</option>
                                <option value="none"<?= $s['smtp_encryption'] === 'none' ? ' selected' : '' ?>>None</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="smtp_username">Username</label>
                            <input type="text" class="form-control" id="smtp_username" name="smtp_username" value="<?= e($s['smtp_username']) ?>" autocomplete="off" placeholder="alerts@agency.com">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="smtp_password">Password</label>
                            <input type="password" class="form-control" id="smtp_password" name="smtp_password" value="" placeholder="<?= $s['smtp_password_set'] ? '•••••••••• (saved — leave blank to keep)' : 'Enter password' ?>" autocomplete="new-password">
                            <div class="form-text">Stored encrypted and never sent back to the browser. Leave blank to keep the saved password.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="smtp_from_email">From address</label>
                            <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" value="<?= e($s['smtp_from_email']) ?>" placeholder="alerts@agency.com">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="smtp_from_name">From name</label>
                            <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" value="<?= e($s['smtp_from_name']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="notification_recipients">Recipients</label>
                            <textarea class="form-control" id="notification_recipients" name="notification_recipients" rows="2" placeholder="ops@agency.com, alerts@agency.com"><?= e($s['notification_recipients']) ?></textarea>
                            <div class="form-text">One or more addresses separated by a comma, semicolon or new line.</div>
                        </div>
                    </div>
                </div>
                <div class="sw-card-footer justify-content-between">
                    <button type="button" class="btn btn-light" id="testEmail"><i class="bi bi-send" aria-hidden="true"></i> Send test email</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save email settings</button>
                </div>
            </section>
        </form>

        <form id="telegramForm" data-section="telegram" novalidate>
            <section class="sw-card mb-4">
                <div class="sw-card-header">
                    <div><h3>Telegram</h3><p class="sub">Instant alerts through a Telegram bot</p></div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="telegram_enabled" name="telegram_enabled" value="1"<?= $s['telegram_enabled'] ? ' checked' : '' ?>>
                        <label class="form-check-label fw-600" for="telegram_enabled">Enabled</label>
                    </div>
                </div>
                <div class="sw-card-body">
                    <ol class="text-muted fs-13 ps-3 mb-3">
                        <li>Open <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> in Telegram and create a bot with <span class="code-inline">/newbot</span>. Copy the token it gives you.</li>
                        <li>Send any message to your new bot (or add it to a group or channel as an administrator).</li>
                        <li>Open <span class="code-inline">https://api.telegram.org/bot&lt;token&gt;/getUpdates</span> in a browser and copy the <span class="code-inline">chat.id</span> value. Group and channel ids start with a minus sign.</li>
                    </ol>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="telegram_bot_token">Bot token</label>
                            <input type="password" class="form-control" id="telegram_bot_token" name="telegram_bot_token" value="" placeholder="<?= $s['telegram_bot_token_set'] ? '•••••••••• (saved — leave blank to keep)' : '123456789:AAF…' ?>" autocomplete="new-password">
                            <div class="form-text">Stored encrypted and never sent back to the browser.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="telegram_chat_id">Chat ID</label>
                            <input type="text" class="form-control" id="telegram_chat_id" name="telegram_chat_id" value="<?= e($s['telegram_chat_id']) ?>" placeholder="-1001234567890">
                        </div>
                    </div>
                </div>
                <div class="sw-card-footer justify-content-between">
                    <button type="button" class="btn btn-light" id="testTelegram"><i class="bi bi-send" aria-hidden="true"></i> Send test message</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save Telegram settings</button>
                </div>
            </section>
        </form>
    </div>

    <div class="col-xxl-5">
        <form id="alertsForm" data-section="alerts" novalidate>
            <section class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Alert rules</h3><p class="sub">Global rules — each website can switch them off individually</p></div></div>
                <div class="sw-card-body">
                    <div class="option-list">
                        <?php foreach ($alertRules as $key => [$label, $desc]): ?>
                            <label class="option-card">
                                <input type="checkbox" class="form-check-input" name="<?= e($key) ?>" value="1"<?= $s[$key] ? ' checked' : '' ?>>
                                <span class="min-w-0"><span class="t"><?= e($label) ?></span><span class="d"><?= e($desc) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="sw-card-footer justify-content-end">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save alert rules</button>
                </div>
            </section>
        </form>

        <section class="sw-card">
            <div class="sw-card-header">
                <div><h3>Delivery history</h3><p class="sub">Every notification attempt, newest first</p></div>
                <button type="button" class="btn-icon btn-sm" id="notifRefresh" aria-label="Refresh delivery history" data-bs-toggle="tooltip" title="Refresh"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
            </div>
            <div id="notifLog"></div>
            <div class="sw-pagination" id="notifPagination"></div>
        </section>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
