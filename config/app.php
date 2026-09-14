<?php

declare(strict_types=1);

/**
 * Application infrastructure configuration.
 *
 * Values come from the .env file. Behavioural settings that an administrator can change
 * at runtime (thresholds, SMTP, etc.) live in the database `settings` table instead.
 */

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    if (is_string($value)) {
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
    }
    return $value;
};

return [
    'name'     => 'SiteWatch',
    'version'  => '1.0.0',
    'env'      => (string) $env('APP_ENV', 'production'),
    'debug'    => (bool) $env('APP_DEBUG', false),
    'url'      => (string) $env('APP_URL', ''),
    'timezone' => (string) $env('APP_TIMEZONE', 'Asia/Karachi'),
    'key'      => (string) $env('APP_KEY', ''),

    'session' => [
        'name'          => 'sitewatch_session',
        'lifetime'      => (int) $env('SESSION_LIFETIME', 480), // minutes of inactivity before logout
        'remember_days' => 30,
    ],

    'auth' => [
        'max_attempts'    => (int) $env('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) $env('LOGIN_LOCKOUT_MINUTES', 15),
    ],

    'monitor' => [
        // Allow monitoring targets that resolve to private / loopback / link-local addresses.
        // Only enable in a trusted internal environment. See README "SSRF protection".
        'allow_private_targets' => (bool) $env('MONITOR_ALLOW_PRIVATE', false),
        'user_agent' => (string) $env(
            'MONITOR_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 SiteWatch/1.0'
        ),
        'max_due_per_run' => (int) $env('MONITOR_MAX_PER_RUN', 300),
        'body_limit_bytes' => 512 * 1024, // inspect at most 512 KB of a response body
    ],

    'paths' => [
        'root'    => dirname(__DIR__),
        'storage' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
        'logs'    => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
        'cache'   => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache',
        'locks'   => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'locks',
    ],

    'log' => [
        'level'     => (string) $env('LOG_LEVEL', 'info'),
        'max_files' => (int) $env('LOG_MAX_FILES', 14),
    ],
];
