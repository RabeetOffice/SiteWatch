<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Key/value application settings with defaults and an in-memory cache.
 * Sensitive keys are encrypted at rest with the APP_KEY.
 */
final class SettingsRepository
{
    /**
     * Settings stored encrypted. Never returned to the browser.
     *
     * A Discord webhook URL is listed here because the token inside it is the whole credential: anyone
     * holding the URL can post to the channel.
     */
    public const SECRET_KEYS = [
        'smtp_password',
        'telegram_bot_token',
        'ipinfo_token',
        'whatsapp_callmebot_apikey',
        'whatsapp_cloud_token',
        'whatsapp_green_token',
        'discord_webhook_url',
        'pagespeed_api_key',
    ];

    public const DEFAULTS = [
        // General
        'app_name'                  => 'SiteWatch',
        'app_url'                   => '',
        'app_timezone'              => 'Asia/Karachi',
        'dashboard_refresh_seconds' => 30,
        'favicon_provider'          => 'google', // google | none

        // Monitoring
        'default_check_interval'         => 5,      // minutes
        'failure_threshold'              => 3,      // consecutive failures before an incident is opened
        'recovery_threshold'             => 2,      // consecutive successes before an incident is resolved
        'request_timeout'                => 30,     // seconds
        'connect_timeout'                => 10,     // seconds
        'max_redirects'                  => 10,
        'moderate_threshold'             => 2000,   // ms  (display only: healthy < moderate)
        'slow_threshold'                 => 5000,   // ms  (>= slow -> SLOW warning)
        'critical_performance_threshold' => 10000,  // ms  (>= critical -> CRITICAL_PERFORMANCE failure)
        'concurrency'                    => 15,     // websites per concurrent batch
        'check_retention_days'           => 30,
        'activity_retention_days'        => 90,
        'notification_retention_days'    => 90,
        'heartbeat_threshold_minutes'    => 3,      // engine considered stopped after this many minutes without a run
        'ssl_check_interval_hours'       => 12,
        'ssl_warning_days'               => 30,

        // Alert rules (global defaults; per-site overrides in websites.alerts_json)
        'alert_down'     => 1,
        'alert_critical' => 1,
        'alert_database' => 1,
        'alert_http'     => 1,
        'alert_timeout'  => 1,
        'alert_ssl'      => 1,
        'alert_slow'     => 1,
        'alert_recovery' => 1,

        // Email
        'email_enabled'           => 0,
        'smtp_host'               => '',
        'smtp_port'               => 587,
        'smtp_username'           => '',
        'smtp_password'           => '',
        'smtp_encryption'         => 'tls', // tls | ssl | none
        'smtp_from_email'         => '',
        'smtp_from_name'          => 'SiteWatch',
        'notification_recipients' => '',

        // Telegram
        'telegram_enabled'   => 0,
        'telegram_bot_token' => '',
        'telegram_chat_id'   => '',

        // WhatsApp (green_api: free plan, own number by QR — cloud_api: Meta official — callmebot: free relay)
        'whatsapp_enabled'          => 0,
        'whatsapp_provider'         => 'green_api', // green_api | cloud_api | callmebot
        'whatsapp_phone'            => '',          // destination number in international format
        'whatsapp_green_instance'   => '',          // idInstance from the GREEN-API console
        'whatsapp_green_token'      => '',          // apiTokenInstance
        'whatsapp_green_api_url'    => '',          // per-account host; blank uses api.green-api.com
        'whatsapp_cloud_phone_id'   => '',
        'whatsapp_cloud_token'      => '',
        'whatsapp_cloud_template'   => '',          // approved template name; empty sends plain text
        'whatsapp_cloud_language'   => 'en_US',
        'whatsapp_callmebot_apikey' => '',

        // Discord (incoming channel webhook)
        'discord_enabled'     => 0,
        'discord_webhook_url' => '',
        'discord_username'    => '', // overrides the webhook's own name
        'discord_mention'     => '', // @here, @everyone or <@&ROLE_ID>

        // Core Web Vitals (Google PageSpeed Insights)
        'vitals_enabled'         => 0,
        'vitals_interval_hours'  => 24,   // a PageSpeed run drives a real browser; hourly would burn the quota
        'vitals_strategies'      => 'both', // mobile | desktop | both
        'vitals_retention_days'  => 180,
        'pagespeed_api_key'      => '',   // optional, raises the free quota

        // Website screenshots
        'screenshot_enabled'          => 0,
        'screenshot_provider'         => 'mshots', // mshots | thumio | pagespeed
        'screenshot_interval_minutes' => 60,       // 0 = capture on every check of the website
        'screenshot_retention_days'   => 14,
        'screenshot_keep_per_website' => 30,

        // Domains & hosting
        'domain_check_interval_hours' => 24,   // WHOIS/RDAP and hosting details are refreshed no more often than this
        'domain_geo_lookup'           => 1,    // city-level hosting location via ipinfo.io
        'ipinfo_token'                => '',   // optional, raises ipinfo.io rate limits
    ];

    /** @var array<string, string|null>|null */
    private ?array $cache = null;
    private ?Crypto $crypto = null;

    public function __construct(private readonly Database $db)
    {
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        foreach ($this->db->fetchAll('SELECT `key`, `value` FROM settings') as $row) {
            $this->cache[(string) $row['key']] = $row['value'];
        }
    }

    public function reload(): void
    {
        $this->cache = null;
    }

    private function crypto(): Crypto
    {
        return $this->crypto ??= Crypto::fromConfig();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        if (array_key_exists($key, $this->cache) && $this->cache[$key] !== null && $this->cache[$key] !== '') {
            $value = $this->cache[$key];
            if (in_array($key, self::SECRET_KEYS, true)) {
                return $this->crypto()->decrypt($value);
            }
            return $value;
        }
        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function getInt(string $key, ?int $default = null): int
    {
        return (int) $this->get($key, $default);
    }

    public function getFloat(string $key, ?float $default = null): float
    {
        return (float) $this->get($key, $default);
    }

    public function getBool(string $key, ?bool $default = null): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return $value === null ? $default : (string) $value;
    }

    public function has(string $key): bool
    {
        $this->load();
        return array_key_exists($key, $this->cache);
    }

    public function set(string $key, mixed $value): void
    {
        $this->load();
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value !== null) {
            $value = (string) $value;
        }
        if (in_array($key, self::SECRET_KEYS, true) && $value !== null && $value !== '') {
            $value = $this->crypto()->encrypt($value);
        }
        $now = utc_now()->format('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
            ['k' => $key, 'v' => $value, 'u' => $now]
        );
        $this->cache[$key] = $value;
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value);
        }
    }

    /**
     * Every setting with defaults applied; secrets are replaced by a boolean "is set" marker.
     *
     * @return array<string, mixed>
     */
    public function allForDisplay(): array
    {
        $this->load();
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                $out[$key] = '';
                $out[$key . '_set'] = $this->get($key, '') !== '';
                continue;
            }
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }
}
