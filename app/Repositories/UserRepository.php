<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    private const SELECT = 'SELECT u.*, r.name AS role_name, r.slug AS role_slug, r.permissions AS role_permissions, r.is_system AS role_is_system
                            FROM users u
                            LEFT JOIN roles r ON r.id = u.role_id';

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch(self::SELECT . ' WHERE u.id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch(self::SELECT . ' WHERE u.email = :email LIMIT 1', ['email' => mb_strtolower(trim($email))]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(self::SELECT . ' ORDER BY u.is_active DESC, u.name ASC');
    }

    public function count(): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM users');
    }

    public function countActiveWithRole(int $roleId): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM users WHERE role_id = :role AND is_active = 1', ['role' => $roleId]);
    }

    public function create(string $name, string $email, string $password, int $roleId, bool $active = true): int
    {
        $now = $this->now();
        return $this->db->insert('users', [
            'name'          => mb_substr(trim($name), 0, 100),
            'email'         => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role_id'       => $roleId,
            'is_active'     => $active ? 1 : 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    public function updateProfile(int $id, string $name, string $email): int
    {
        return $this->db->update('users', [
            'name'       => mb_substr(trim($name), 0, 100),
            'email'      => mb_strtolower(trim($email)),
            'updated_at' => $this->now(),
        ], 'id = :id', ['id' => $id]);
    }

    /**
     * Update an account from user management. Deactivating a user also ends their sessions.
     */
    public function updateAccount(int $id, string $name, string $email, int $roleId, bool $active): int
    {
        $count = $this->db->update('users', [
            'name'       => mb_substr(trim($name), 0, 100),
            'email'      => mb_strtolower(trim($email)),
            'role_id'    => $roleId,
            'is_active'  => $active ? 1 : 0,
            'updated_at' => $this->now(),
        ], 'id = :id', ['id' => $id]);
        if (!$active) {
            $this->revokeSessions($id);
        }
        return $count;
    }

    public function updatePassword(int $id, string $password): int
    {
        $count = $this->db->update('users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at'    => $this->now(),
        ], 'id = :id', ['id' => $id]);
        // A new password signs the account out of every other browser.
        $this->revokeSessions($id);
        return $count;
    }

    /**
     * Invalidate every "remember me" token and every signed-in session of the user.
     */
    public function revokeSessions(int $id): void
    {
        $this->db->delete('remember_tokens', 'user_id = :id', ['id' => $id]);
        $this->db->query('UPDATE users SET session_version = session_version + 1 WHERE id = :id', ['id' => $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('users', 'id = :id', ['id' => $id]);
    }
}
