<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Notifications\DiscordNotifier;
use App\Notifications\EmailNotifier;
use App\Notifications\NotificationManager;
use App\Notifications\WhatsAppNotifier;
use App\Performance\ScreenshotCapturer;
use App\Repositories\ActivityRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\WebsiteRepository;
use App\Services\ActivityService;

Api::boot(['POST']);

$input = Request::all();
$section = Request::string('section');
Api::authorize(in_array($section, ['general', 'monitoring', 'performance'], true) ? 'settings.manage' : 'notifications.manage');
$settings = App::settings();
$v = new Validator($input);
$values = [];
$reload = false;

$bool = static fn (string $key): bool => in_array(strtolower((string) ($input[$key] ?? '0')), ['1', 'true', 'on', 'yes'], true);
$int = static fn (string $key, int $default): int => is_numeric($input[$key] ?? null) ? (int) $input[$key] : $default;
$str = static fn (string $key): string => trim((string) ($input[$key] ?? ''));

switch ($section) {
    case 'general':
        $v->required('app_name', 'Application name')->max('app_name', 100, 'Application name');
        $v->url('app_url', 'Application URL')->max('app_url', 255, 'Application URL');
        $v->required('app_timezone', 'Timezone')->timezone('app_timezone', 'Timezone');
        $v->integer('dashboard_refresh_seconds', 15, 300, 'Dashboard refresh');
        $v->in('favicon_provider', ['google', 'duckduckgo', 'none'], 'Favicon provider');
        if ($v->passes()) {
            $values = [
                'app_name'                  => $str('app_name'),
                'app_url'                   => rtrim($str('app_url'), '/'),
                'app_timezone'              => $str('app_timezone'),
                'dashboard_refresh_seconds' => $int('dashboard_refresh_seconds', 30),
                'favicon_provider'          => $str('favicon_provider') ?: 'google',
            ];
            $reload = $values['app_timezone'] !== $settings->getString('app_timezone') || $values['app_name'] !== $settings->getString('app_name');
        }
        break;

    case 'monitoring':
        $v->in('default_check_interval', WebsiteRepository::INTERVALS, 'Default interval');
        $v->integer('failure_threshold', 1, 10, 'Failure threshold');
        $v->integer('recovery_threshold', 1, 10, 'Recovery threshold');
        $v->integer('request_timeout', 5, 120, 'Request timeout');
        $v->integer('connect_timeout', 2, 60, 'Connect timeout');
        $v->integer('max_redirects', 0, 20, 'Redirect limit');
        $v->integer('moderate_threshold', 100, 60000, 'Healthy threshold');
        $v->integer('slow_threshold', 200, 120000, 'Slow threshold');
        $v->integer('critical_performance_threshold', 500, 120000, 'Critical performance threshold');
        $v->integer('concurrency', 1, 50, 'Concurrency');
        $v->in('check_retention_days', [7, 30, 60, 90], 'Check retention');
        $v->in('activity_retention_days', SettingsRepository::ACTIVITY_RETENTION_CHOICES, 'Activity retention');
        $v->integer('notification_retention_days', 7, 3650, 'Notification retention');
        $v->integer('heartbeat_threshold_minutes', 1, 60, 'Engine health threshold');
        $v->integer('ssl_check_interval_hours', 1, 168, 'SSL re-check interval');
        $v->integer('domain_check_interval_hours', 1, 720, 'Domain re-check interval');
        if ($str('ipinfo_token') !== '' && !preg_match('/^[A-Za-z0-9]{6,64}$/', $str('ipinfo_token'))) {
            $v->addError('ipinfo_token', 'An ipinfo.io token contains only letters and numbers.');
        }
        if ($int('critical_performance_threshold', 10000) <= $int('slow_threshold', 5000)) {
            $v->addError('critical_performance_threshold', 'Must be greater than the slow threshold.');
        }
        if ($int('slow_threshold', 5000) <= $int('moderate_threshold', 2000)) {
            $v->addError('slow_threshold', 'Must be greater than the healthy threshold.');
        }
        if ($int('connect_timeout', 10) > $int('request_timeout', 30)) {
            $v->addError('connect_timeout', 'Connect timeout cannot exceed the request timeout.');
        }
        if ($v->passes()) {
            foreach (['default_check_interval', 'failure_threshold', 'recovery_threshold', 'request_timeout', 'connect_timeout', 'max_redirects', 'moderate_threshold', 'slow_threshold', 'critical_performance_threshold', 'concurrency', 'check_retention_days', 'activity_retention_days', 'notification_retention_days', 'heartbeat_threshold_minutes', 'ssl_check_interval_hours', 'domain_check_interval_hours'] as $key) {
                $values[$key] = $int($key, (int) SettingsRepository::DEFAULTS[$key]);
            }
            $values['domain_geo_lookup'] = $bool('domain_geo_lookup');
            if ($str('ipinfo_token') !== '') {
                $values['ipinfo_token'] = $str('ipinfo_token');
            }
        }
        break;

    case 'email':
        $v->max('smtp_host', 255, 'SMTP host')->regex('smtp_host', '/^[A-Za-z0-9.\-]*$/', 'SMTP host must be a hostname.');
        $v->integer('smtp_port', 1, 65535, 'SMTP port');
        $v->max('smtp_username', 255, 'SMTP username');
        $v->in('smtp_encryption', ['tls', 'ssl', 'none'], 'Encryption');
        $v->email('smtp_from_email', 'From email')->max('smtp_from_email', 255, 'From email');
        $v->max('smtp_from_name', 100, 'From name');
        $recipients = EmailNotifier::parseRecipients($str('notification_recipients'));
        $rawRecipients = array_filter(array_map('trim', preg_split('/[\s,;]+/', $str('notification_recipients')) ?: []));
        if (count($rawRecipients) !== count($recipients)) {
            $v->addError('notification_recipients', 'One or more recipient addresses are invalid.');
        }
        if ($bool('email_enabled')) {
            if ($str('smtp_host') === '') {
                $v->addError('smtp_host', 'SMTP host is required when email alerts are enabled.');
            }
            if ($recipients === []) {
                $v->addError('notification_recipients', 'Add at least one recipient when email alerts are enabled.');
            }
        }
        if ($v->passes()) {
            $values = [
                'email_enabled'           => $bool('email_enabled'),
                'smtp_host'               => $str('smtp_host'),
                'smtp_port'               => $int('smtp_port', 587),
                'smtp_username'           => $str('smtp_username'),
                'smtp_encryption'         => $str('smtp_encryption') ?: 'tls',
                'smtp_from_email'         => $str('smtp_from_email'),
                'smtp_from_name'          => $str('smtp_from_name') ?: 'SiteWatch',
                'notification_recipients' => implode(', ', $recipients),
            ];
            if ($str('smtp_password') !== '') {
                $values['smtp_password'] = (string) ($input['smtp_password'] ?? '');
            }
        }
        break;

    case 'telegram':
        $v->max('telegram_chat_id', 64, 'Chat ID')->regex('telegram_chat_id', '/^-?\d*$|^@[A-Za-z0-9_]{5,}$/', 'Chat ID must be numeric (or an @channel username).');
        if ($str('telegram_bot_token') !== '' && !preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $str('telegram_bot_token'))) {
            $v->addError('telegram_bot_token', 'The bot token format looks invalid (expected 123456789:ABC…).');
        }
        if ($bool('telegram_enabled')) {
            if ($str('telegram_bot_token') === '' && $settings->getString('telegram_bot_token') === '') {
                $v->addError('telegram_bot_token', 'A bot token is required when Telegram alerts are enabled.');
            }
            if ($str('telegram_chat_id') === '') {
                $v->addError('telegram_chat_id', 'A chat ID is required when Telegram alerts are enabled.');
            }
        }
        if ($v->passes()) {
            $values = ['telegram_enabled' => $bool('telegram_enabled'), 'telegram_chat_id' => $str('telegram_chat_id')];
            if ($str('telegram_bot_token') !== '') {
                $values['telegram_bot_token'] = $str('telegram_bot_token');
            }
        }
        break;

    case 'performance':
        $v->in('vitals_strategies', ['mobile', 'desktop', 'both'], 'Strategies');
        $v->integer('vitals_interval_hours', 1, 720, 'Vitals re-check interval');
        $v->integer('vitals_retention_days', 7, 3650, 'Vitals retention');
        $v->in('screenshot_provider', ScreenshotCapturer::PROVIDERS, 'Screenshot provider');
        $v->integer('screenshot_interval_minutes', 0, 10080, 'Screenshot interval');
        $v->integer('screenshot_retention_days', 1, 3650, 'Screenshot retention');
        $v->integer('screenshot_keep_per_website', 1, 500, 'Screenshots kept per website');
        $v->regex('pagespeed_api_key', '/^[A-Za-z0-9_-]{20,60}$/', 'A Google API key is 39 characters of letters, numbers, hyphens and underscores.');

        if ($bool('vitals_enabled') && $str('pagespeed_api_key') === '' && $settings->getString('pagespeed_api_key') === '') {
            // The keyless PageSpeed quota is a pool shared by every anonymous caller and is almost always
            // exhausted, so enabling vitals without a key would just record failures.
            $v->addError('pagespeed_api_key', 'A free Google API key is required — the anonymous PageSpeed quota is shared and is normally exhausted.');
        }
        if ($v->passes()) {
            $values = [
                'vitals_enabled'              => $bool('vitals_enabled'),
                'vitals_interval_hours'       => $int('vitals_interval_hours', 24),
                'vitals_strategies'           => $str('vitals_strategies') ?: 'both',
                'vitals_retention_days'       => $int('vitals_retention_days', 180),
                'screenshot_enabled'          => $bool('screenshot_enabled'),
                'screenshot_provider'         => $str('screenshot_provider') ?: ScreenshotCapturer::DEFAULT_PROVIDER,
                'screenshot_interval_minutes' => $int('screenshot_interval_minutes', 60),
                'screenshot_retention_days'   => $int('screenshot_retention_days', 14),
                'screenshot_keep_per_website' => $int('screenshot_keep_per_website', 30),
            ];
            if ($str('pagespeed_api_key') !== '') {
                $values['pagespeed_api_key'] = $str('pagespeed_api_key');
            }
        }
        break;

    case 'whatsapp':
        $provider = in_array($str('whatsapp_provider'), WhatsAppNotifier::PROVIDERS, true) ? $str('whatsapp_provider') : WhatsAppNotifier::DEFAULT_PROVIDER;
        $phone = WhatsAppNotifier::normalizePhone($str('whatsapp_phone'));
        if ($str('whatsapp_phone') !== '' && $phone === '') {
            $v->addError('whatsapp_phone', 'Enter the number in international format, for example +923001234567.');
        }
        $v->regex('whatsapp_green_instance', '/^\d{4,20}$/', 'The instance ID is the number shown as idInstance in the GREEN-API console.');
        if ($str('whatsapp_green_api_url') !== '' && WhatsAppNotifier::greenApiUrl($str('whatsapp_green_api_url')) !== rtrim($str('whatsapp_green_api_url'), '/')) {
            $v->addError('whatsapp_green_api_url', 'Use the ApiUrl from the GREEN-API console, for example https://7103.api.greenapi.com.');
        }
        $v->max('whatsapp_cloud_phone_id', 32, 'Phone number ID')->regex('whatsapp_cloud_phone_id', '/^\d{5,32}$/', 'The phone number ID is the numeric ID from Meta, not the phone number itself.');
        $v->regex('whatsapp_cloud_template', '/^[a-z0-9_]{1,512}$/', 'A template name uses lowercase letters, numbers and underscores only.');
        $v->regex('whatsapp_cloud_language', '/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', 'Use a language code such as en_US, en or es.');
        if ($bool('whatsapp_enabled')) {
            if ($phone === '') {
                $v->addError('whatsapp_phone', 'A destination number is required when WhatsApp alerts are enabled.');
            }
            if ($provider === 'green_api') {
                if ($str('whatsapp_green_instance') === '') {
                    $v->addError('whatsapp_green_instance', 'An instance ID is required for the GREEN-API provider.');
                }
                if ($str('whatsapp_green_token') === '' && $settings->getString('whatsapp_green_token') === '') {
                    $v->addError('whatsapp_green_token', 'An API token is required for the GREEN-API provider.');
                }
            }
            if ($provider === 'callmebot' && $str('whatsapp_callmebot_apikey') === '' && $settings->getString('whatsapp_callmebot_apikey') === '') {
                $v->addError('whatsapp_callmebot_apikey', 'A CallMeBot API key is required when WhatsApp alerts are enabled.');
            }
            if ($provider === 'cloud_api') {
                if ($str('whatsapp_cloud_phone_id') === '') {
                    $v->addError('whatsapp_cloud_phone_id', 'A phone number ID is required for the Cloud API provider.');
                }
                if ($str('whatsapp_cloud_token') === '' && $settings->getString('whatsapp_cloud_token') === '') {
                    $v->addError('whatsapp_cloud_token', 'An access token is required for the Cloud API provider.');
                }
            }
        }
        if ($v->passes()) {
            $values = [
                'whatsapp_enabled'        => $bool('whatsapp_enabled'),
                'whatsapp_provider'       => $provider,
                'whatsapp_phone'          => $phone,
                'whatsapp_green_instance' => $str('whatsapp_green_instance'),
                'whatsapp_green_api_url'  => rtrim($str('whatsapp_green_api_url'), '/'),
                'whatsapp_cloud_phone_id' => $str('whatsapp_cloud_phone_id'),
                'whatsapp_cloud_template' => $str('whatsapp_cloud_template'),
                'whatsapp_cloud_language' => $str('whatsapp_cloud_language') ?: 'en_US',
            ];
            foreach (['whatsapp_callmebot_apikey', 'whatsapp_cloud_token', 'whatsapp_green_token'] as $secret) {
                if ($str($secret) !== '') {
                    $values[$secret] = $str($secret);
                }
            }
        }
        break;

    case 'discord':
        $webhook = $str('discord_webhook_url');
        if ($webhook !== '' && !DiscordNotifier::isValidWebhook($webhook)) {
            $v->addError('discord_webhook_url', 'Paste the full webhook URL copied from Discord (https://discord.com/api/webhooks/…).');
        }
        $v->max('discord_username', 80, 'Bot name');
        $v->regex('discord_mention', '/^(@here|@everyone|<@&\d{5,25}>)$/', 'Use @here, @everyone or a role mention such as <@&123456789012345678>.');
        if ($bool('discord_enabled') && $webhook === '' && $settings->getString('discord_webhook_url') === '') {
            $v->addError('discord_webhook_url', 'A webhook URL is required when Discord alerts are enabled.');
        }
        if ($v->passes()) {
            $values = [
                'discord_enabled'  => $bool('discord_enabled'),
                'discord_username' => $str('discord_username'),
                'discord_mention'  => $str('discord_mention'),
            ];
            if ($webhook !== '') {
                $values['discord_webhook_url'] = $webhook;
            }
        }
        break;

    case 'alerts':
        foreach (['alert_down', 'alert_critical', 'alert_database', 'alert_http', 'alert_timeout', 'alert_ssl', 'alert_slow', 'alert_recovery', 'alert_wp_error', 'alert_security'] as $key) {
            $values[$key] = $bool($key);
        }
        break;

    default:
        Response::error('Unknown settings section.', ['section' => 'Invalid section.'], 422);
}

if ($v->fails()) {
    Response::error('Please correct the highlighted fields.', $v->errors(), 422);
}

$previousActivityDays = $settings->getInt('activity_retention_days', 30);
$settings->setMany($values);
$settings->reload();

// A shorter activity log period takes effect now instead of at the next daily cleanup.
$purgedActivity = 0;
$activityDays = $settings->getInt('activity_retention_days', 30);
if ($section === 'monitoring' && $activityDays > 0 && ($previousActivityDays === 0 || $activityDays < $previousActivityDays)) {
    try {
        $purgedActivity = (new ActivityRepository(App::db()))->purgeOlderThan(utc_now()->modify("-{$activityDays} days")->format('Y-m-d H:i:s'));
    } catch (Throwable $e) {
        App::logger('app')->warning('Activity purge after settings change failed', ['error' => $e->getMessage()]);
    }
}
App::resetTimezone();

// Only the names of the changed settings are recorded, and never the encrypted ones.
$logged = array_diff_key($values, array_flip(SettingsRepository::SECRET_KEYS));
$label = NotificationManager::CHANNEL_LABELS[$section] ?? ucfirst($section);
ActivityService::log('settings.changed', $label . ' settings updated', null, ['section' => $section, 'keys' => array_keys($logged)]);

$message = $label . ' settings saved.' . ($purgedActivity > 0 ? " {$purgedActivity} old activity " . ($purgedActivity === 1 ? 'entry was' : 'entries were') . ' removed.' : '');
Response::success($message, ['section' => $section, 'reload' => $reload, 'settings' => $settings->allForDisplay(), 'activity_purged' => $purgedActivity]);
