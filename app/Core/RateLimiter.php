<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Login rate limiting backed by the login_attempts table.
 * Blocks an IP address or e-mail address after N failed attempts inside the lockout window.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $db,
        private readonly int $maxAttempts = 5,
        private readonly int $lockoutMinutes = 15
    ) {
    }

    /**
     * @return int Seconds the caller must wait before another attempt (0 = allowed now).
     */
    public function secondsUntilRetry(string $ip, string $email): int
    {
        $since = utc_now()->modify('-' . max(1, $this->lockoutMinutes) . ' minutes')->format('Y-m-d H:i:s');
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS c, MAX(created_at) AS last FROM login_attempts
             WHERE success = 0 AND created_at >= :since AND (ip = :ip OR email = :email)',
            ['since' => $since, 'ip' => $ip, 'email' => mb_strtolower($email)]
        );
        $count = (int) ($row['c'] ?? 0);
        if ($count < $this->maxAttempts) {
            return 0;
        }
        $last = $row['last'] ? strtotime($row['last'] . ' UTC') : time();
        $retryAt = $last + $this->lockoutMinutes * 60;
        return max(1, $retryAt - time());
    }

    public function record(string $ip, string $email, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip'         => substr($ip, 0, 45),
            'email'      => mb_substr(mb_strtolower($email), 0, 190),
            'success'    => $success ? 1 : 0,
            'created_at' => utc_now()->format('Y-m-d H:i:s'),
        ]);
        // Opportunistic purge of old rows (cheap thanks to the created_at index).
        if (random_int(1, 20) === 1) {
            $this->purge();
        }
    }

    public function clear(string $ip, string $email): void
    {
        $this->db->delete('login_attempts', 'success = 0 AND (ip = :ip OR email = :email)', [
            'ip' => $ip, 'email' => mb_strtolower($email),
        ]);
    }

    public function purge(): void
    {
        $cutoff = utc_now()->modify('-1 day')->format('Y-m-d H:i:s');
        $this->db->delete('login_attempts', 'created_at < :cutoff', ['cutoff' => $cutoff]);
    }
}
