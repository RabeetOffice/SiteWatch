<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\SettingsRepository;
use DateTimeZone;
use Monolog\Logger as MonologLogger;

/**
 * Minimal service locator. Holds lazily-created singletons for the infrastructure
 * services that every request and cron process needs.
 */
final class App
{
    private static ?Config $config = null;
    private static ?Database $db = null;
    private static ?Session $session = null;
    private static ?CSRF $csrf = null;
    private static ?Auth $auth = null;
    private static ?SettingsRepository $settings = null;
    private static ?DateTimeZone $timezone = null;
    /** @var array<string, MonologLogger> */
    private static array $loggers = [];
    private static bool $booted = false;

    public static function boot(Config $config): void
    {
        self::$config = $config;
        self::$booted = true;
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    public static function config(): Config
    {
        if (self::$config === null) {
            throw new \RuntimeException('Application has not been booted.');
        }
        return self::$config;
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            self::$db = new Database((array) self::config()->get('database', []));
        }
        return self::$db;
    }

    public static function settings(): SettingsRepository
    {
        if (self::$settings === null) {
            self::$settings = new SettingsRepository(self::db());
        }
        return self::$settings;
    }

    public static function session(): Session
    {
        if (self::$session === null) {
            self::$session = new Session((array) self::config()->get('app.session', []));
        }
        return self::$session;
    }

    public static function csrf(): CSRF
    {
        if (self::$csrf === null) {
            self::$csrf = new CSRF(self::session());
        }
        return self::$csrf;
    }

    public static function auth(): Auth
    {
        if (self::$auth === null) {
            self::$auth = new Auth(self::session(), self::db(), (array) self::config()->get('app.auth', []), (array) self::config()->get('app.session', []));
        }
        return self::$auth;
    }

    public static function logger(string $channel = 'app'): MonologLogger
    {
        if (!isset(self::$loggers[$channel])) {
            self::$loggers[$channel] = Logger::create(
                $channel,
                (string) self::config()->get('app.paths.logs'),
                (string) self::config()->get('app.log.level', 'info'),
                (int) self::config()->get('app.log.max_files', 14)
            );
        }
        return self::$loggers[$channel];
    }

    /**
     * The timezone used for display. Falls back to the .env value, then Asia/Karachi.
     * Database storage is always UTC.
     */
    public static function timezone(): DateTimeZone
    {
        if (self::$timezone === null) {
            $name = (string) self::config()->get('app.timezone', 'Asia/Karachi');
            if (self::isInstalled()) {
                try {
                    $stored = (string) self::settings()->get('app_timezone', '');
                    if ($stored !== '') {
                        $name = $stored;
                    }
                } catch (\Throwable) {
                    // Settings table not reachable yet (installer). Use .env value.
                }
            }
            try {
                self::$timezone = new DateTimeZone($name);
            } catch (\Throwable) {
                self::$timezone = new DateTimeZone('Asia/Karachi');
            }
        }
        return self::$timezone;
    }

    public static function resetTimezone(): void
    {
        self::$timezone = null;
    }

    public static function isInstalled(): bool
    {
        return is_file(self::config()->get('app.paths.storage') . DIRECTORY_SEPARATOR . 'install.lock')
            && is_file(self::config()->get('app.paths.root') . DIRECTORY_SEPARATOR . '.env');
    }

    /**
     * Schema version recorded in the database (0 when unknown).
     */
    public static function schemaVersion(): int
    {
        return self::settings()->getInt(Migrator::SETTING, 0);
    }

    /**
     * True when the deployed code needs database updates that have not been applied yet.
     */
    public static function schemaPending(): bool
    {
        return self::schemaVersion() < Migrator::VERSION;
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * Best-effort base URL detection when APP_URL is not configured (e.g. installer).
     */
    public static function detectBaseUrl(): string
    {
        if (self::isCli()) {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Strip anything that is not a valid host[:port]
        $host = preg_replace('/[^A-Za-z0-9\.\-\:\[\]]/', '', $host) ?? 'localhost';

        $script = $_SERVER['SCRIPT_NAME'] ?? '/';
        $script = str_replace('\\', '/', $script);
        $root = str_replace('\\', '/', (string) self::config()->get('app.paths.root'));
        $file = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        // Work out the URL path that maps to the project root directory.
        $base = '';
        if ($file !== '' && str_starts_with($file, $root)) {
            $relative = substr($file, strlen($root)); // e.g. /admin/dashboard.php
            if ($relative !== '' && str_ends_with($script, $relative)) {
                $base = substr($script, 0, strlen($script) - strlen($relative));
            }
        }
        if ($base === '') {
            $base = rtrim(dirname($script), '/');
        }
        return $scheme . '://' . $host . rtrim($base, '/');
    }
}
