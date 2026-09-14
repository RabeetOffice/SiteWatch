<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small static helpers around the current HTTP request.
 */
final class Request
{
    /** @var array<string, mixed>|null */
    private static ?array $jsonCache = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /**
     * Decoded JSON body (empty array when not JSON).
     *
     * @return array<string, mixed>
     */
    public static function json(): array
    {
        if (self::$jsonCache !== null) {
            return self::$jsonCache;
        }
        self::$jsonCache = [];
        $type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($type, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    self::$jsonCache = $decoded;
                }
            }
        }
        return self::$jsonCache;
    }

    /**
     * Merged input: JSON body overrides form fields, which override query parameters.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_merge($_GET, $_POST, self::json());
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::input($key, $default);
        if (is_array($value) || $value === null) {
            return $default;
        }
        return trim((string) $value);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::input($key);
        if ($value === null || $value === '' || is_array($value)) {
            return $default;
        }
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::input($key);
        if ($value === null || is_array($value)) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<int, mixed> */
    public static function array(string $key): array
    {
        $value = self::input($key);
        return is_array($value) ? array_values($value) : [];
    }

    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function path(): string
    {
        return (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    }
}
