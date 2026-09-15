<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Permission;

final class RoleRepository extends BaseRepository
{
    /** Slug of the built-in role that always holds every permission. */
    public const ADMINISTRATOR = 'administrator';

    /** Roles created on install / upgrade. Only Administrator is locked; the others can be edited or deleted. */
    public const DEFAULTS = [
        [
            'slug'        => self::ADMINISTRATOR,
            'name'        => 'Administrator',
            'description' => 'Full access to everything, including users, roles and settings.',
            'permissions' => [Permission::ALL],
            'is_system'   => 1,
        ],
        [
            'slug'        => 'manager',
            'name'        => 'Manager',
            'description' => 'Manages websites and monitoring data. No access to users, roles, settings or notifications.',
            'permissions' => ['websites.manage', 'websites.check', 'websites.delete', 'incidents.view', 'reports.view', 'domains.view', 'domains.lookup', 'activity.view'],
            'is_system'   => 0,
        ],
        [
            'slug'        => 'viewer',
            'name'        => 'Viewer',
            'description' => 'Read-only access to websites, incidents, reports and domain information.',
            'permissions' => ['incidents.view', 'reports.view', 'domains.view'],
            'is_system'   => 0,
        ],
    ];

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM roles WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetch('SELECT * FROM roles WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->db->fetch('SELECT * FROM roles WHERE name = :name LIMIT 1', ['name' => trim($name)]);
    }

    /**
     * Every role with the number of users assigned to it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->db->fetchAll(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM users ua WHERE ua.role_id = r.id AND ua.is_active = 1) AS active_count
             FROM roles r
             ORDER BY r.is_system DESC, r.name ASC'
        );
    }

    /** @param array<int, string> $permissions */
    public function create(string $name, string $description, array $permissions, bool $system = false, ?string $slug = null): int
    {
        $now = $this->now();
        return $this->db->insert('roles', [
            'name'        => mb_substr(trim($name), 0, 60),
            'slug'        => $slug ?? $this->uniqueSlug($name),
            'description' => mb_substr(trim($description), 0, 255),
            'permissions' => json_encode(array_values($permissions)),
            'is_system'   => $system ? 1 : 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    /** @param array<int, string> $permissions */
    public function update(int $id, string $name, string $description, array $permissions): int
    {
        // The slug is an internal identifier and stays stable when a role is renamed.
        return $this->db->update('roles', [
            'name'        => mb_substr(trim($name), 0, 60),
            'description' => mb_substr(trim($description), 0, 255),
            'permissions' => json_encode(array_values($permissions)),
            'updated_at'  => $this->now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('roles', 'id = :id AND is_system = 0', ['id' => $id]);
    }

    public function userCount(int $id): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM users WHERE role_id = :id', ['id' => $id]);
    }

    /**
     * Insert any default role that does not exist yet (matched by slug).
     */
    public function seedDefaults(): void
    {
        foreach (self::DEFAULTS as $role) {
            if ($this->findBySlug($role['slug']) === null) {
                $name = $this->findByName($role['name']) === null ? $role['name'] : $role['name'] . ' (default)';
                $this->create($name, $role['description'], $role['permissions'], (bool) $role['is_system'], $role['slug']);
            }
        }
    }

    public function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base !== '' ? $base : 'role', 0, 50);
        $slug = $base;
        for ($i = 2; $this->findBySlug($slug) !== null; $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }
}
