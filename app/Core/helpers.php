<?php

declare(strict_types=1);

/**
 * Global helper functions. Kept intentionally small; everything else lives in classes.
 */

use App\Core\App;

if (!function_exists('e')) {
    /**
     * HTML-escape a value for safe output.
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return App::config()->get($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return App::settings()->get($key, $default);
    }
}

if (!function_exists('base_url')) {
    /**
     * Build an absolute application URL (APP_URL + path).
     */
    function base_url(string $path = ''): string
    {
        $base = rtrim((string) App::config()->get('app.url', ''), '/');
        if ($base === '') {
            $base = App::detectBaseUrl();
        }
        $path = ltrim($path, '/');
        return $path === '' ? $base : $base . '/' . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $version = (string) App::config()->get('app.version', '1');
        // Add the file's modification time so browsers fetch a changed file after every deploy,
        // even when the app version stays the same.
        $mtime = @filemtime(dirname(__DIR__, 2) . '/assets/' . ltrim($path, '/'));
        if ($mtime !== false) {
            $version .= '.' . $mtime;
        }
        return base_url('assets/' . ltrim($path, '/')) . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('app_timezone')) {
    function app_timezone(): DateTimeZone
    {
        return App::timezone();
    }
}

if (!function_exists('utc_now')) {
    function utc_now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

if (!function_exists('to_local')) {
    /**
     * Convert a UTC datetime string (as stored in the database) into the configured application timezone.
     */
    function to_local(?string $utc): ?DateTimeImmutable
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000-00-00')) {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
        return $dt->setTimezone(app_timezone());
    }
}

if (!function_exists('format_datetime')) {
    /**
     * Format a UTC database timestamp for display, e.g. "14 Sep 2026 · 1:42 PM".
     */
    function format_datetime(?string $utc, string $format = 'j M Y · g:i A', string $empty = '—'): string
    {
        $local = to_local($utc);
        return $local ? $local->format($format) : $empty;
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $utc, string $empty = '—'): string
    {
        return format_datetime($utc, 'j M Y', $empty);
    }
}

if (!function_exists('time_ago')) {
    /**
     * Human readable relative time: "36 sec ago", "8 min ago", "3 hours ago".
     */
    function time_ago(?string $utc, string $empty = 'Never'): string
    {
        if ($utc === null || $utc === '') {
            return $empty;
        }
        try {
            $then = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return $empty;
        }
        $diff = time() - $then->getTimestamp();
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 5) {
            return 'just now';
        }
        if ($diff < 60) {
            return $diff . ' sec ago';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . ' min ago';
        }
        if ($diff < 86400) {
            $h = intdiv($diff, 3600);
            return $h . ($h === 1 ? ' hour ago' : ' hours ago');
        }
        $d = intdiv($diff, 86400);
        if ($d < 30) {
            return $d . ($d === 1 ? ' day ago' : ' days ago');
        }
        return $then->setTimezone(app_timezone())->format('j M Y');
    }
}

if (!function_exists('format_ms')) {
    /**
     * Format milliseconds for display: "842 ms" or "1.24 s".
     */
    function format_ms(int|float|string|null $ms, string $empty = '—'): string
    {
        if ($ms === null || $ms === '') {
            return $empty;
        }
        $ms = (float) $ms;
        if ($ms < 1000) {
            return round($ms) . ' ms';
        }
        return number_format($ms / 1000, 2) . ' s';
    }
}

if (!function_exists('format_duration')) {
    /**
     * Format a number of seconds as a compact human duration: "9 minutes", "2h 15m", "3d 4h".
     */
    function format_duration(int|float|null $seconds, string $empty = '—'): string
    {
        if ($seconds === null) {
            return $empty;
        }
        $seconds = (int) round($seconds);
        if ($seconds < 60) {
            return $seconds . ' sec';
        }
        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            return $m . ($m === 1 ? ' minute' : ' minutes');
        }
        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
        }
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        return $d . 'd' . ($h > 0 ? ' ' . $h . 'h' : '');
    }
}

if (!function_exists('format_uptime')) {
    /**
     * Format an uptime percentage with sensible precision (99.98%).
     */
    function format_uptime(float|int|string|null $pct, string $empty = '—'): string
    {
        if ($pct === null || $pct === '') {
            return $empty;
        }
        $pct = (float) $pct;
        if ($pct >= 100) {
            return '100%';
        }
        return number_format($pct, 2) . '%';
    }
}

if (!function_exists('str_limit')) {
    function str_limit(?string $value, int $limit = 120, string $end = '…'): string
    {
        $value = (string) $value;
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $limit)) . $end;
    }
}

if (!function_exists('array_get')) {
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }
}

if (!function_exists('json_out')) {
    /**
     * Encode a value for safe embedding inside a <script> block.
     */
    function json_out(mixed $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?: 'null';
    }
}

if (!function_exists('can')) {
    /**
     * Whether the signed-in user's role grants a permission (see App\Core\Permission).
     */
    function can(string $permission): bool
    {
        return App::auth()->can($permission);
    }
}

if (!function_exists('require_permission')) {
    /**
     * Page guard: the visitor must be signed in and hold $permission; otherwise an "access denied" page is shown.
     */
    function require_permission(string $permission): void
    {
        $auth = App::auth();
        $auth->requireLogin();
        if ($auth->can($permission)) {
            return;
        }
        if (\App\Core\Request::isAjax()) {
            \App\Core\Response::error('You do not have permission to open this page.', [], 403);
        }
        http_response_code(403);
        $deniedPermission = $permission;
        require dirname(__DIR__, 2) . '/includes/forbidden.php';
        exit;
    }
}
