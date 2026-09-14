<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Secure session wrapper: strict mode, HttpOnly + SameSite cookies, idle timeout, flash messages.
 */
final class Session
{
    private bool $started = false;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $lifetimeMinutes = max(5, (int) ($this->config['lifetime'] ?? 480));

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) ($lifetimeMinutes * 60));
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        session_name((string) ($this->config['name'] ?? 'sitewatch_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $this->cookiePath(),
            'domain'   => '',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        $this->started = true;

        // Idle timeout
        $now = time();
        $last = (int) ($_SESSION['_last_activity'] ?? $now);
        if ($now - $last > $lifetimeMinutes * 60) {
            $this->destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        if (!isset($_SESSION['_flash'][$key])) {
            return $default;
        }
        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
            session_destroy();
        }
        $this->started = false;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }

    /**
     * Cookie path scoped to the application (works when installed in a sub-directory such as /sitewatch).
     */
    private function cookiePath(): string
    {
        try {
            $url = (string) App::config()->get('app.url', '');
            if ($url !== '') {
                $path = parse_url($url, PHP_URL_PATH);
                if (is_string($path) && $path !== '' && $path !== '/') {
                    return rtrim($path, '/') . '/';
                }
                return '/';
            }
        } catch (\Throwable) {
            // fall through
        }
        return '/';
    }
}
