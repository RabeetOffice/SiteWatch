<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => mb_strtolower(trim($email))]);
    }

    public function count(): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM users');
    }

    public function create(string $name, string $email, string $password, string $role = 'admin'): int
    {
        $now = $this->now();
        return $this->db->insert('users', [
            'name'          => mb_substr(trim($name), 0, 100),
            'email'         => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'is_active'     => 1,
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

    public function updatePassword(int $id, string $password): int
    {
        // Changing the password invalidates every remember-me token.
        $this->db->delete('remember_tokens', 'user_id = :id', ['id' => $id]);
        return $this->db->update('users', [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at'    => $this->now(),
        ], 'id = :id', ['id' => $id]);
    }
}
