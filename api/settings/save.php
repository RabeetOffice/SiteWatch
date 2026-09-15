<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Notifications\EmailNotifier;
use App\Repositories\SettingsRepository;
use App\Repositories\WebsiteRepository;
use App\Services\ActivityService;

Api::boot(['POST']);

$input = Request::all();
$section = Request::string('section');
Api::authorize(in_array($section, ['general', 'monitoring'], true) ? 'settings.manage' : 'notifications.manage');
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
        $v->integer('activity_retention_days', 7, 3650, 'Activity retention');
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

    case 'alerts':
        foreach (['alert_down', 'alert_critical', 'alert_database', 'alert_http', 'alert_timeout', 'alert_ssl', 'alert_slow', 'alert_recovery'] as $key) {
            $values[$key] = $bool($key);
        }
        break;

    default:
        Response::error('Unknown settings section.', ['section' => 'Invalid section.'], 422);
}

if ($v->fails()) {
    Response::error('Please correct the highlighted fields.', $v->errors(), 422);
}

$settings->setMany($values);
$settings->reload();
App::resetTimezone();

$logged = $values;
unset($logged['smtp_password'], $logged['telegram_bot_token'], $logged['ipinfo_token']);
ActivityService::log('settings.changed', ucfirst($section) . ' settings updated', null, ['section' => $section, 'keys' => array_keys($logged)]);

Response::success(ucfirst($section) . ' settings saved.', ['section' => $section, 'reload' => $reload, 'settings' => $settings->allForDisplay()]);
