<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authentication and authorisation: login with rate limiting, session regeneration, "remember me" cookies
 * (selector/validator pattern), logout, and permission checks against the signed-in user's role.
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';
    private const VERSION_KEY = '_auth_session_version';
    private const REMEMBER_COOKIE = 'sitewatch_remember';

    /** @var array<string, mixed>|null */
    private ?array $user = null;
    private bool $resolved = false;
    private RateLimiter $limiter;

    /**
     * @param array<string, mixed> $authConfig
     * @param array<string, mixed> $sessionConfig
     */
    public function __construct(
        private readonly Session $session,
        private readonly Database $db,
        private readonly array $authConfig = [],
        private readonly array $sessionConfig = []
    ) {
        $this->limiter = new RateLimiter(
            $db,
            max(3, (int) ($authConfig['max_attempts'] ?? 5)),
            max(1, (int) ($authConfig['lockout_minutes'] ?? 15))
        );
    }

    /**
     * @return array{success: bool, message: string, retry_after?: int}
     */
    public function attempt(string $email, string $password, bool $remember, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $password === '') {
            return ['success' => false, 'message' => 'Please enter your email address and password.'];
        }

        $wait = $this->limiter->secondsUntilRetry($ip, $email);
        if ($wait > 0) {
            $minutes = (int) ceil($wait / 60);
            return [
                'success'     => false,
                'message'     => "Too many failed sign-in attempts. Please try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.',
                'retry_after' => $wait,
            ];
        }

        $user = $this->db->fetch('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
        $valid = $user !== null
            && (int) $user['is_active'] === 1
            && password_verify($password, (string) $user['password_hash']);

        if (!$valid) {
            // Constant-ish time even when the user does not exist.
            if ($user === null) {
                password_verify($password, '$2y$12$C6UzMDM.H6dfI/f/IKcEeO6JBFwMcJqBTKnCqK8p/VfJZJ7Dmv3.K');
            }
            $this->limiter->record($ip, $email, false);
            return ['success' => false, 'message' => 'Invalid email address or password.'];
        }

        // Upgrade hashes when the default algorithm/cost changes.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
        }

        $this->limiter->record($ip, $email, true);
        $this->limiter->clear($ip, $email);

        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, (int) $user['id']);
        $this->session->set(self::VERSION_KEY, (int) ($user['session_version'] ?? 1));
        $this->session->set('_auth_time', time());
        App::csrf()->rotate();

        $this->db->update('users', [
            'last_login_at' => utc_now()->format('Y-m-d H:i:s'),
            'last_login_ip' => substr($ip, 0, 45),
            'updated_at'    => utc_now()->format('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $user['id']]);

        if ($remember) {
            $this->issueRememberToken((int) $user['id']);
        }

        $this->user = $this->fetchActiveUser((int) $user['id']);
        $this->resolved = true;

        return ['success' => true, 'message' => 'Signed in successfully.'];
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The signed-in user, including role_name, role_slug and the decoded `permissions` list.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $id = $this->session->get(self::SESSION_KEY);
        if (is_int($id) && $id > 0) {
            $user = $this->fetchActiveUser($id);
            $version = $this->session->get(self::VERSION_KEY);
            if ($user !== null && is_int($version) && $version !== (int) $user['session_version']) {
                // The password was reset or the account deactivated elsewhere: this session has ended.
                $user = null;
            }
            if ($user === null) {
                $this->session->remove(self::SESSION_KEY);
                $this->session->remove(self::VERSION_KEY);
                return $this->user = null;
            }
            if (!is_int($version)) {
                // Sessions created before session versions existed adopt the current one.
                $this->session->set(self::VERSION_KEY, (int) $user['session_version']);
            }
            return $this->user = $user;
        }

        if ($this->loginFromRememberCookie()) {
            return $this->user;
        }
        return null;
    }

    public function id(): ?int
    {
        $user = $this->user();
        return $user ? (int) $user['id'] : null;
    }

    /**
     * Permission keys granted by the user's role ("*" for administrators).
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return $this->user()['permissions'] ?? [];
    }

    public function can(string $permission): bool
    {
        return Permission::allows($this->permissions(), $permission);
    }

    public function isAdministrator(): bool
    {
        return in_array(Permission::ALL, $this->permissions(), true);
    }

    public function refreshUser(): void
    {
        $this->resolved = false;
        $this->user = null;
    }

    /**
     * Keep the current browser signed in after the user's session version changed on purpose
     * (for example when they changed their own password).
     */
    public function syncSessionVersion(): void
    {
        $id = $this->session->get(self::SESSION_KEY);
        $user = is_int($id) ? $this->fetchActiveUser($id) : null;
        if ($user !== null) {
            $this->session->set(self::VERSION_KEY, (int) $user['session_version']);
        }
        $this->user = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        $this->clearRememberToken();
        $this->session->destroy();
        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Redirect to the login page when the visitor is not signed in.
     */
    public function requireLogin(): void
    {
        if ($this->check()) {
            return;
        }
        if (Request::isAjax()) {
            Response::error('Authentication required.', [], 401);
        }
        $next = $_SERVER['REQUEST_URI'] ?? '';
        $query = ($next !== '' && !str_contains($next, 'login.php')) ? '?next=' . rawurlencode($next) : '';
        Response::redirect(base_url('login.php') . $query);
    }

    /** @return array<string, mixed>|null */
    private function fetchActiveUser(int $id): ?array
    {
        if (App::schemaVersion() < 2) {
            // The database has not been updated to roles yet (schema 1), when every account was an administrator.
            // This lets an administrator sign in and apply the update under System → Updates.
            $user = $this->db->fetch('SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $id]);
            if ($user !== null) {
                $user += ['role_name' => 'Administrator', 'role_slug' => 'administrator', 'role_permissions' => '["*"]'];
                $user['session_version'] = (int) ($user['session_version'] ?? 1);
                $user['permissions'] = [Permission::ALL];
            }
            return $user;
        }

        $user = $this->db->fetch(
            'SELECT u.*, r.name AS role_name, r.slug AS role_slug, r.permissions AS role_permissions
             FROM users u
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.is_active = 1
             LIMIT 1',
            ['id' => $id]
        );
        if ($user !== null) {
            $user['permissions'] = Permission::decode($user['role_permissions'] ?? null);
        }
        return $user;
    }

    // ------------------------------------------------------------------
    // Remember-me
    // ------------------------------------------------------------------

    private function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));           // 24 chars
        $validator = bin2hex(random_bytes(32));          // 64 chars, stored hashed
        $days = max(1, (int) ($this->sessionConfig['remember_days'] ?? 30));
        $expires = utc_now()->modify("+{$days} days");

        $this->db->insert('remember_tokens', [
            'user_id'        => $userId,
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at'     => $expires->format('Y-m-d H:i:s'),
            'created_at'     => utc_now()->format('Y-m-d H:i:s'),
        ]);

        // Remove expired tokens for this user.
        $this->db->delete('remember_tokens', 'user_id = :uid AND expires_at < :now', [
            'uid' => $userId, 'now' => utc_now()->format('Y-m-d H:i:s'),
        ]);

        $this->setRememberCookie($selector . ':' . $validator, $expires->getTimestamp());
    }

    private function loginFromRememberCookie(): bool
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return false;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            $this->setRememberCookie('', time() - 3600);
            return false;
        }

        $token = $this->db->fetch('SELECT * FROM remember_tokens WHERE selector = :s LIMIT 1', ['s' => $selector]);
        if ($token === null || strtotime($token['expires_at'] . ' UTC') < time()) {
            $this->setRememberCookie('', time() - 3600);
            return false;
        }
        if (!hash_equals((string) $token['validator_hash'], hash('sha256', $validator))) {
            // Possible token theft: invalidate every token for the user.
            $this->db->delete('remember_tokens', 'user_id = :uid', ['uid' => $token['user_id']]);
            $this->setRememberCookie('', time() - 3600);
            return false;
        }

        $user = $this->fetchActiveUser((int) $token['user_id']);
        if ($user === null) {
            return false;
        }

        // Rotate the token on every use.
        $this->db->delete('remember_tokens', 'id = :id', ['id' => $token['id']]);
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, (int) $user['id']);
        $this->session->set(self::VERSION_KEY, (int) $user['session_version']);
        $this->session->set('_auth_time', time());
        $this->issueRememberToken((int) $user['id']);
        $this->user = $user;
        return true;
    }

    private function clearRememberToken(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($cookie) && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
                $this->db->delete('remember_tokens', 'selector = :s', ['s' => $selector]);
            }
        }
        $this->setRememberCookie('', time() - 3600);
    }

    private function setRememberCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        $params = session_get_cookie_params();
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => $params['path'] ?: '/',
            'domain'   => '',
            'secure'   => Session::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
