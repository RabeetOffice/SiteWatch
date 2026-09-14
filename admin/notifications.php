<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;

$s = App::settings()->allForDisplay();

$pageTitle = 'Notifications';
$pageSubtitle = 'Alert channels, global alert rules and the delivery log.';
$activeNav = 'notifications';
$pageScripts = ['settings.js'];
$pageData = ['section' => 'notifications'];

require dirname(__DIR__) . '/includes/header.php';

$alertRules = [
    'alert_down'     => ['Website Down', 'Outages, connection failures, DNS errors, redirect problems and maintenance mode'],
    'alert_critical' => ['WordPress Critical Error', 'The WordPress "critical error" page or an exposed PHP fatal error'],
    'alert_database' => ['Database Error', '"Error establishing a database connection" and related failures'],
    'alert_http'     => ['HTTP 5xx / 4xx', 'Server errors (500, 502, 503, 504) and error responses from the homepage'],
    'alert_timeout'  => ['Timeout', 'Requests that exceed the configured timeout'],
    'alert_ssl'      => ['SSL', 'Certificate errors and expiry warnings (30 / 14 / 7 days, expired)'],
    'alert_slow'     => ['Slow', 'Critically slow responses (performance incidents)'],
    'alert_recovery' => ['Recovery', 'One recovery notification when a website comes back'],
];
?>

<div class="row g-4">
    <div class="col-xl-7">
        <form id="emailForm" data-section="email" novalidate>
            <div class="sw-card mb-4">
                <div class="sw-card-header">
                    <div><h3>Email (SMTP)</h3><p class="sub">Delivered with PHPMailer over SMTP</p></div>
                    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="email_enabled" name="email_enabled" value="1"<?= $s['email_enabled'] ? ' checked' : '' ?>><label class="form-check-label fw-600" for="email_enabled">Enabled</label></div>
                </div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="smtp_host">SMTP Host</label><input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?= e($s['smtp_host']) ?>" placeholder="smtp.example.com" autocomplete="off"></div>
                        <div class="col-md-4"><label class="form-label" for="smtp_port">SMTP Port</label><input type="number" class="form-control" id="smtp_port" name="smtp_port" min="1" max="65535" value="<?= (int) $s['smtp_port'] ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="smtp_username">SMTP Username</label><input type="text" class="form-control" id="smtp_username" name="smtp_username" value="<?= e($s['smtp_username']) ?>" autocomplete="off"></div>
                        <div class="col-md-6"><label class="form-label" for="smtp_password">SMTP Password</label><input type="password" class="form-control" id="smtp_password" name="smtp_password" value="" placeholder="<?= $s['smtp_password_set'] ? '•••••••••• (saved — leave blank to keep)' : 'Enter password' ?>" autocomplete="new-password"><div class="form-text">Stored encrypted. Never sent back to the browser.</div></div>
                        <div class="col-md-4"><label class="form-label" for="smtp_encryption">Encryption</label><select class="form-select" id="smtp_encryption" name="smtp_encryption"><option value="tls"<?= $s['smtp_encryption'] === 'tls' ? ' selected' : '' ?>>STARTTLS (587)</option><option value="ssl"<?= $s['smtp_encryption'] === 'ssl' ? ' selected' : '' ?>>SSL/TLS (465)</option><option value="none"<?= $s['smtp_encryption'] === 'none' ? ' selected' : '' ?>>None</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="smtp_from_email">From Email</label><input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" value="<?= e($s['smtp_from_email']) ?>" placeholder="alerts@agency.com"></div>
                        <div class="col-md-4"><label class="form-label" for="smtp_from_name">From Name</label><input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" value="<?= e($s['smtp_from_name']) ?>"></div>
                        <div class="col-12"><label class="form-label" for="notification_recipients">Recipients</label><textarea class="form-control" id="notification_recipients" name="notification_recipients" rows="2" placeholder="ops@agency.com, alerts@agency.com"><?= e($s['notification_recipients']) ?></textarea><div class="form-text">Comma, semicolon or newline separated e-mail addresses.</div></div>
                    </div>
                </div>
                <div class="sw-card-footer d-flex justify-content-between flex-wrap gap-2">
                    <button type="button" class="btn btn-light" id="testEmail"><i class="bi bi-send"></i> Send Test Email</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Email Settings</button>
                </div>
            </div>
        </form>

        <form id="telegramForm" data-section="telegram" novalidate>
            <div class="sw-card mb-4">
                <div class="sw-card-header">
                    <div><h3>Telegram</h3><p class="sub">Instant alerts through a Telegram bot</p></div>
                    <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="telegram_enabled" name="telegram_enabled" value="1"<?= $s['telegram_enabled'] ? ' checked' : '' ?>><label class="form-check-label fw-600" for="telegram_enabled">Enabled</label></div>
                </div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-7"><label class="form-label" for="telegram_bot_token">Bot Token</label><input type="password" class="form-control" id="telegram_bot_token" name="telegram_bot_token" value="" placeholder="<?= $s['telegram_bot_token_set'] ? '•••••••••• (saved — leave blank to keep)' : '123456789:AAF…' ?>" autocomplete="new-password"><div class="form-text">Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>. Stored encrypted.</div></div>
                        <div class="col-md-5"><label class="form-label" for="telegram_chat_id">Chat ID</label><input type="text" class="form-control" id="telegram_chat_id" name="telegram_chat_id" value="<?= e($s['telegram_chat_id']) ?>" placeholder="-1001234567890"><div class="form-text">User, group or channel ID (see README).</div></div>
                    </div>
                </div>
                <div class="sw-card-footer d-flex justify-content-between flex-wrap gap-2">
                    <button type="button" class="btn btn-light" id="testTelegram"><i class="bi bi-send"></i> Test Telegram Alert</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Telegram Settings</button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-xl-5">
        <form id="alertsForm" data-section="alerts" novalidate>
            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Alert Rules</h3><p class="sub">Global rules · each website can override them</p></div></div>
                <div class="sw-card-body">
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($alertRules as $key => [$label, $desc]): ?>
                            <label class="option-card"><input type="checkbox" class="form-check-input" name="<?= e($key) ?>" value="1"<?= $s[$key] ? ' checked' : '' ?>><span><span class="t d-block"><?= e($label) ?></span><span class="d"><?= e($desc) ?></span></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="sw-card-footer d-flex justify-content-end"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Alert Rules</button></div>
            </div>
        </form>

        <div class="sw-card">
            <div class="sw-card-header"><div><h3>Delivery Log</h3><p class="sub">Every notification attempt</p></div><button type="button" class="btn-icon btn-sm" id="notifRefresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button></div>
            <div id="notifLog"></div>
            <div class="sw-pagination" id="notifPagination"></div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
