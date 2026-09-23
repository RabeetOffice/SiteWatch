<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_permission('notifications.manage');

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
    'alert_wp_error' => ['WordPress fatal error (plugin)', 'The file, line and plugin or theme behind a fatal error, and plugins switched off by auto-fix, reported by the SiteWatch Connector plugin'],
    'alert_security' => ['WordPress security (plugin)', 'New administrator accounts, administrator role changes and site address changes reported by the plugin'],
    'alert_vulnerability' => ['Known vulnerabilities (plugin)', 'Installed plugins, themes or WordPress versions with a known security vulnerability, checked daily against the WPVulnerability database'],
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

        <form id="whatsappForm" data-section="whatsapp" novalidate>
            <section class="sw-card mb-4">
                <div class="sw-card-header">
                    <div><h3>WhatsApp</h3><p class="sub">Alerts on your phone — three providers, all with a free option</p></div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="whatsapp_enabled" name="whatsapp_enabled" value="1"<?= $s['whatsapp_enabled'] ? ' checked' : '' ?>>
                        <label class="form-check-label fw-600" for="whatsapp_enabled">Enabled</label>
                    </div>
                </div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="whatsapp_provider">Delivery method</label>
                            <select class="form-select" id="whatsapp_provider" name="whatsapp_provider">
                                <option value="green_api"<?= $s['whatsapp_provider'] === 'green_api' ? ' selected' : '' ?>>GREEN-API — free plan, quickest to set up</option>
                                <option value="cloud_api"<?= $s['whatsapp_provider'] === 'cloud_api' ? ' selected' : '' ?>>WhatsApp Cloud API — Meta, official</option>
                                <option value="callmebot"<?= $s['whatsapp_provider'] === 'callmebot' ? ' selected' : '' ?>>CallMeBot — free, activation often fails</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="whatsapp_phone">Destination number</label>
                            <input type="tel" class="form-control" id="whatsapp_phone" name="whatsapp_phone" value="<?= e($s['whatsapp_phone']) ?>" placeholder="+923001234567" autocomplete="off">
                            <div class="form-text">International format, including the country code.</div>
                        </div>
                    </div>

                    <div class="mt-3" data-whatsapp-provider="green_api">
                        <ol class="text-muted fs-13 ps-3 mb-3">
                            <li>Create a free account at <a href="https://green-api.com/en/" target="_blank" rel="noopener">green-api.com</a> and add an instance on the <b>Developer</b> plan — it is free, needs no card and does not expire.</li>
                            <li>Open the instance and scan the QR code with the WhatsApp you want alerts to be <em>sent from</em>. Wait until its state shows <span class="code-inline">authorized</span>.</li>
                            <li>Copy <span class="code-inline">idInstance</span>, <span class="code-inline">apiTokenInstance</span> and <span class="code-inline">ApiUrl</span> from the console into the fields below, save, then send a test message.</li>
                        </ol>
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label" for="whatsapp_green_instance">Instance ID</label>
                                <input type="text" class="form-control" id="whatsapp_green_instance" name="whatsapp_green_instance" value="<?= e($s['whatsapp_green_instance']) ?>" placeholder="7103123456" autocomplete="off">
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" for="whatsapp_green_token">API token</label>
                                <input type="password" class="form-control" id="whatsapp_green_token" name="whatsapp_green_token" value="" placeholder="<?= $s['whatsapp_green_token_set'] ? '•••••••••• (saved — leave blank to keep)' : 'apiTokenInstance' ?>" autocomplete="new-password">
                                <div class="form-text">Stored encrypted and never sent back to the browser.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="whatsapp_green_api_url">API URL <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="url" class="form-control" id="whatsapp_green_api_url" name="whatsapp_green_api_url" value="<?= e($s['whatsapp_green_api_url']) ?>" placeholder="https://api.green-api.com" autocomplete="off">
                                <div class="form-text">Newer accounts get a numbered host such as <span class="code-inline">https://7103.api.greenapi.com</span>. Leave blank to use the default.</div>
                            </div>
                        </div>
                        <div class="form-text mt-2">
                            Alerts are sent from your own WhatsApp number, so there are no message templates to get approved.
                            The free plan talks to three chats, which is enough for alerting. It works through WhatsApp Web
                            rather than an official API, so use a number you would not mind having restricted, and keep the
                            alert volume low.
                        </div>
                    </div>

                    <div class="mt-3" data-whatsapp-provider="callmebot">
                        <ol class="text-muted fs-13 ps-3 mb-3">
                            <li>Save the WhatsApp number <span class="code-inline">+34 623 78 95 80</span> to your phone contacts as <b>CallMeBot</b>. Check the <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener">CallMeBot page</a> if that number has changed.</li>
                            <li>From the phone you entered above, send it the message <span class="code-inline">I allow callmebot to send me messages</span>.</li>
                            <li>It replies with an API key within a couple of minutes. Paste that key below, save, then send a test message.</li>
                        </ol>
                        <label class="form-label" for="whatsapp_callmebot_apikey">CallMeBot API key</label>
                        <input type="password" class="form-control" id="whatsapp_callmebot_apikey" name="whatsapp_callmebot_apikey" value="" placeholder="<?= $s['whatsapp_callmebot_apikey_set'] ? '•••••••••• (saved — leave blank to keep)' : '123456' ?>" autocomplete="new-password">
                        <div class="form-text">
                            Stored encrypted and never sent back to the browser. CallMeBot is free for personal use and
                            only delivers to the one number that authorised it, so it suits a single on-call phone rather
                            than a whole team. Its activation bot is run as a hobby service and often never replies — if
                            no key arrives within a few minutes, send <span class="code-inline">Recover APIKey</span> to the
                            same contact, and if that is silent too, use GREEN-API or the Cloud API instead.
                        </div>
                    </div>

                    <div class="mt-3" data-whatsapp-provider="cloud_api">
                        <p class="text-muted fs-13 mb-3">
                            Meta's official platform. The API itself costs nothing, but alerts are business-initiated, so
                            they need an <b>approved template</b> and Meta meters them once the number passes its free
                            allowance. Leave the template empty only if the recipient messages your business number first —
                            plain text is delivered inside that 24-hour window only.
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="whatsapp_cloud_phone_id">Phone number ID</label>
                                <input type="text" class="form-control" id="whatsapp_cloud_phone_id" name="whatsapp_cloud_phone_id" value="<?= e($s['whatsapp_cloud_phone_id']) ?>" placeholder="123456789012345" autocomplete="off">
                                <div class="form-text">From the WhatsApp section of your Meta app — a numeric ID, not the phone number.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="whatsapp_cloud_token">Access token</label>
                                <input type="password" class="form-control" id="whatsapp_cloud_token" name="whatsapp_cloud_token" value="" placeholder="<?= $s['whatsapp_cloud_token_set'] ? '•••••••••• (saved — leave blank to keep)' : 'EAAG…' ?>" autocomplete="new-password">
                                <div class="form-text">Use a permanent system-user token; temporary tokens expire after 24 hours.</div>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" for="whatsapp_cloud_template">Template name <span class="text-muted fw-normal">(recommended)</span></label>
                                <input type="text" class="form-control" id="whatsapp_cloud_template" name="whatsapp_cloud_template" value="<?= e($s['whatsapp_cloud_template']) ?>" placeholder="sitewatch_alert" autocomplete="off">
                                <div class="form-text">The alert is passed to the template as <span class="code-inline">{{1}}</span>, on a single line.</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="whatsapp_cloud_language">Template language</label>
                                <input type="text" class="form-control" id="whatsapp_cloud_language" name="whatsapp_cloud_language" value="<?= e($s['whatsapp_cloud_language']) ?>" placeholder="en_US" autocomplete="off">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="sw-card-footer justify-content-between">
                    <button type="button" class="btn btn-light" id="testWhatsapp"><i class="bi bi-send" aria-hidden="true"></i> Send test message</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save WhatsApp settings</button>
                </div>
            </section>
        </form>

        <form id="discordForm" data-section="discord" novalidate>
            <section class="sw-card mb-4">
                <div class="sw-card-header">
                    <div><h3>Discord</h3><p class="sub">Alerts posted to a channel through an incoming webhook</p></div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="discord_enabled" name="discord_enabled" value="1"<?= $s['discord_enabled'] ? ' checked' : '' ?>>
                        <label class="form-check-label fw-600" for="discord_enabled">Enabled</label>
                    </div>
                </div>
                <div class="sw-card-body">
                    <ol class="text-muted fs-13 ps-3 mb-3">
                        <li>In Discord, open the channel you want alerts in → <b>Edit Channel</b> → <b>Integrations</b> → <b>Webhooks</b>.</li>
                        <li>Create a webhook, then click <b>Copy Webhook URL</b>.</li>
                        <li>Paste it below, save, and send a test message. Webhooks are free and need no bot application.</li>
                    </ol>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="discord_webhook_url">Webhook URL</label>
                            <input type="password" class="form-control" id="discord_webhook_url" name="discord_webhook_url" value="" placeholder="<?= $s['discord_webhook_url_set'] ? '•••••••••• (saved — leave blank to keep)' : 'https://discord.com/api/webhooks/…' ?>" autocomplete="new-password">
                            <div class="form-text">Stored encrypted and never sent back to the browser — anyone holding this URL can post to the channel.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="discord_username">Bot name <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" class="form-control" id="discord_username" name="discord_username" maxlength="80" value="<?= e($s['discord_username']) ?>" placeholder="<?= e($s['app_name']) ?>">
                            <div class="form-text">Overrides the name set on the webhook in Discord.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="discord_mention">Mention <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" class="form-control" id="discord_mention" name="discord_mention" value="<?= e($s['discord_mention']) ?>" placeholder="@here" autocomplete="off">
                            <div class="form-text">Added in front of every alert. Use <span class="code-inline">@here</span>, <span class="code-inline">@everyone</span> or a role such as <span class="code-inline">&lt;@&amp;123456789012345678&gt;</span>.</div>
                        </div>
                    </div>
                </div>
                <div class="sw-card-footer justify-content-between">
                    <button type="button" class="btn btn-light" id="testDiscord"><i class="bi bi-send" aria-hidden="true"></i> Send test message</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save Discord settings</button>
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
