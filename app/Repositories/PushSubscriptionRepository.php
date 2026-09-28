<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Browsers and installed apps that turned on desktop notifications (Web Push).
 */
final class PushSubscriptionRepository extends BaseRepository
{
    /**
     * Add or refresh a subscription. An endpoint belongs to one browser profile, so it moves to the user who
     * subscribed last on that device.
     *
     * @param list<string>|null $events
     */
    public function save(int $userId, string $endpoint, string $p256dh, string $auth, string $encoding, string $device, ?array $events): int
    {
        $hash = hash('sha256', $endpoint);
        $now = $this->now();
        $this->db->query(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, encoding, device, events, created_at)
             VALUES (:user, :endpoint, :hash, :p256dh, :auth, :encoding, :device, :events, :now)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth),
                 encoding = VALUES(encoding), device = VALUES(device), events = VALUES(events), last_error = NULL',
            [
                'user' => $userId, 'endpoint' => $endpoint, 'hash' => $hash, 'p256dh' => $p256dh, 'auth' => $auth,
                'encoding' => $encoding, 'device' => mb_substr($device, 0, 120), 'events' => $events === null ? null : (string) json_encode(array_values($events)), 'now' => $now,
            ]
        );
        return (int) $this->db->fetchColumn('SELECT id FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => $hash]);
    }

    /** @return array<string, mixed>|null */
    public function findByEndpoint(string $endpoint): ?array
    {
        return $this->db->fetch('SELECT * FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => hash('sha256', $endpoint)]);
    }

    public function deleteByEndpoint(int $userId, string $endpoint): int
    {
        return $this->db->delete('push_subscriptions', 'endpoint_hash = :h AND user_id = :u', ['h' => hash('sha256', $endpoint), 'u' => $userId]);
    }

    public function deleteForUser(int $userId, int $id): int
    {
        return $this->db->delete('push_subscriptions', 'id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('push_subscriptions', 'id = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->fetchAll('SELECT * FROM push_subscriptions WHERE user_id = :u ORDER BY created_at DESC', ['u' => $userId]);
    }

    /**
     * Every subscription of an active account, with the role's permissions.
     *
     * @return list<array<string, mixed>>
     */
    public function allActive(): array
    {
        return $this->db->fetchAll(
            'SELECT s.*, r.permissions AS role_permissions FROM push_subscriptions s
             JOIN users u ON u.id = s.user_id AND u.is_active = 1
             LEFT JOIN roles r ON r.id = u.role_id'
        );
    }

    public function count(): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM push_subscriptions');
    }

    public function markUsed(int $id): void
    {
        $this->db->query('UPDATE push_subscriptions SET last_used_at = :now, last_error = NULL WHERE id = :id', ['now' => $this->now(), 'id' => $id]);
    }

    public function markError(int $id, string $error): void
    {
        $this->db->query('UPDATE push_subscriptions SET last_error = :e WHERE id = :id', ['e' => mb_substr($error, 0, 255), 'id' => $id]);
    }
}
