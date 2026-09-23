<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Permission;
use App\Core\Validator;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * User and role management rules.
 *
 * Guard rails: nobody can grant — or take over an account holding — more access than they have themselves;
 * users cannot deactivate, delete or change the role of their own account; the built-in Administrator role is
 * locked; a role that is still assigned cannot be deleted; and at least one active Administrator always remains.
 */
final class TeamService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles
    ) {
    }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $user  Row from UserRepository (with role_permissions)
     * @param array<string, mixed> $actor Signed-in user (with permissions)
     */
    public function canManageUser(array $user, array $actor): bool
    {
        return Permission::covers((array) ($actor['permissions'] ?? []), Permission::decode($user['role_permissions'] ?? null));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $existing
     * @return array{data: array{name: string, email: string, role_id: int, is_active: bool, password: ?string}, errors: array<string, string>}
     */
    public function validateUser(array $input, array $actor, ?array $existing = null): array
    {
        $v = new Validator($input);
        $v->required('name', 'Name')->max('name', 100, 'Name');
        $v->required('email', 'Email address')->email('email', 'Email address')->max('email', 190, 'Email address');

        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if ($existing === null || $password !== '') {
            $v->required('password', 'Password')->min('password', 10, 'Password')->max('password', 200, 'Password');
            if ($password !== '' && strtolower($password) === $email) {
                $v->addError('password', 'The password must not be the email address.');
            }
        }
        if (!isset($v->errors()['email'])) {
            $other = $this->users->findByEmail($email);
            if ($other !== null && ($existing === null || (int) $other['id'] !== (int) $existing['id'])) {
                $v->addError('email', 'Another user already signs in with this email address.');
            }
        }

        $active = self::bool($input['is_active'] ?? true);
        $role = $this->roles->find((int) ($input['role_id'] ?? 0));
        if ($role === null) {
            $v->addError('role_id', 'Choose a role.');
        } elseif (!Permission::covers((array) ($actor['permissions'] ?? []), Permission::decode($role['permissions']))) {
            $v->addError('role_id', 'You cannot assign a role with more access than your own.');
        }

        if ($existing !== null && $role !== null) {
            $isSelf = (int) $existing['id'] === (int) $actor['id'];
            if ($isSelf && (int) $role['id'] !== (int) $existing['role_id']) {
                $v->addError('role_id', 'You cannot change your own role. Ask another administrator.');
            }
            if ($isSelf && !$active) {
                $v->addError('is_active', 'You cannot deactivate your own account.');
            }
            if ($this->removesLastAdministrator($existing, (int) $role['id'], $active)) {
                $v->addError($active ? 'role_id' : 'is_active', 'This is the only active Administrator. Give another user the Administrator role first.');
            }
        }

        return [
            'data' => [
                'name'      => trim((string) ($input['name'] ?? '')),
                'email'     => $email,
                'role_id'   => (int) ($role['id'] ?? 0),
                'is_active' => $active,
                'password'  => $password !== '' ? $password : null,
            ],
            'errors' => $v->errors(),
        ];
    }

    /**
     * @param array{name: string, email: string, role_id: int, is_active: bool, password: ?string} $data
     * @return array<string, mixed>
     */
    public function createUser(array $data): array
    {
        $id = $this->users->create($data['name'], $data['email'], (string) $data['password'], $data['role_id'], $data['is_active']);
        $user = $this->users->find($id) ?? [];
        ActivityService::log('user.created', sprintf('User added: %s (%s) with the %s role', $data['name'], $data['email'], $user['role_name'] ?? 'unknown'), null, [
            'email' => $data['email'],
            'role'  => $user['role_name'] ?? null,
        ]);
        return $user;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array{name: string, email: string, role_id: int, is_active: bool, password: ?string} $data
     * @return array<string, mixed>
     */
    public function updateUser(array $existing, array $data): array
    {
        $id = (int) $existing['id'];
        $this->users->updateAccount($id, $data['name'], $data['email'], $data['role_id'], $data['is_active']);
        if ($data['password'] !== null) {
            $this->users->updatePassword($id, $data['password']);
        }
        $user = $this->users->find($id) ?? [];

        $context = [];
        if ((int) $existing['role_id'] !== $data['role_id']) {
            $context['role'] = ($existing['role_name'] ?? '?') . ' → ' . ($user['role_name'] ?? '?');
        }
        if ((int) $existing['is_active'] !== ($data['is_active'] ? 1 : 0)) {
            $context['status'] = $data['is_active'] ? 'activated' : 'deactivated';
        }
        if ((string) $existing['email'] !== $data['email']) {
            $context['email'] = $existing['email'] . ' → ' . $data['email'];
        }
        if ($data['password'] !== null) {
            $context['password'] = 'reset';
        }
        ActivityService::log('user.updated', sprintf('User updated: %s', $data['name']), null, $context ?: null);
        return $user;
    }

    /**
     * Why the actor may not delete $user, or null when allowed.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $actor
     */
    public function deleteUserError(array $user, array $actor): ?string
    {
        if ((int) $user['id'] === (int) $actor['id']) {
            return 'You cannot delete your own account.';
        }
        if (!$this->canManageUser($user, $actor)) {
            return 'You cannot delete a user who has more access than you.';
        }
        if ($this->removesLastAdministrator($user, 0, false)) {
            return 'This is the only active Administrator and cannot be deleted.';
        }
        return null;
    }

    /** @param array<string, mixed> $user */
    public function deleteUser(array $user): void
    {
        $this->users->delete((int) $user['id']);
        if (!empty($user['avatar'])) {
            AvatarService::create()->deleteFile((string) $user['avatar']);
        }
        ActivityService::log('user.deleted', sprintf('User deleted: %s (%s)', $user['name'], $user['email']), null, ['email' => $user['email']]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function presentUser(array $user, array $actor): array
    {
        $isSelf = (int) $user['id'] === (int) $actor['id'];
        $manageable = $this->canManageUser($user, $actor);
        return [
            'id'               => (int) $user['id'],
            'name'             => (string) $user['name'],
            'email'            => (string) $user['email'],
            'initials'         => self::initials((string) $user['name']),
            'avatar_url'       => AvatarService::url($user),
            'role_id'          => (int) $user['role_id'],
            'role_name'        => (string) ($user['role_name'] ?? ''),
            'full_access'      => in_array(Permission::ALL, Permission::decode($user['role_permissions'] ?? null), true),
            'is_active'        => (int) $user['is_active'] === 1,
            'is_self'          => $isSelf,
            'last_login_at'    => $user['last_login_at'] ?? null,
            'last_login_label' => format_datetime($user['last_login_at'] ?? null, 'j M Y · g:i A', 'Never'),
            'last_login_ip'    => $user['last_login_ip'] ?? null,
            'created_label'    => format_date($user['created_at'] ?? null),
            'can_edit'         => $manageable,
            'can_delete'       => $manageable && !$isSelf,
        ];
    }

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $existing
     * @return array{data: array{name: string, description: string, permissions: array<int, string>}, errors: array<string, string>}
     */
    public function validateRole(array $input, array $actor, ?array $existing = null): array
    {
        $v = new Validator($input);
        $v->required('name', 'Role name')->max('name', 60, 'Role name');
        $v->max('description', 255, 'Description');

        $requested = is_array($input['permissions'] ?? null) ? array_map('strval', array_values($input['permissions'])) : [];
        // Only known keys are accepted from input; the wildcard is reserved for the built-in Administrator role.
        $permissions = Permission::normalize($requested);
        $beyond = array_values(array_diff($permissions, Permission::expand((array) ($actor['permissions'] ?? []))));
        if ($beyond !== []) {
            $v->addError('permissions', 'You can only grant permissions you have yourself: ' . implode(', ', array_map([Permission::class, 'label'], $beyond)) . '.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name !== '') {
            $other = $this->roles->findByName($name);
            if ($other !== null && ($existing === null || (int) $other['id'] !== (int) $existing['id'])) {
                $v->addError('name', 'A role with this name already exists.');
            }
        }

        return [
            'data'   => ['name' => $name, 'description' => trim((string) ($input['description'] ?? '')), 'permissions' => $permissions],
            'errors' => $v->errors(),
        ];
    }

    /**
     * Why the actor may not edit $role, or null when allowed.
     *
     * @param array<string, mixed> $role
     * @param array<string, mixed> $actor
     */
    public function editRoleError(array $role, array $actor): ?string
    {
        if ((int) $role['is_system'] === 1) {
            return 'The Administrator role always has full access and cannot be changed.';
        }
        if (!Permission::covers((array) ($actor['permissions'] ?? []), Permission::decode($role['permissions']))) {
            return 'You cannot change a role that has more access than you.';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $role
     * @param array<string, mixed> $actor
     */
    public function deleteRoleError(array $role, array $actor): ?string
    {
        if ((int) $role['is_system'] === 1) {
            return 'Built-in roles cannot be deleted.';
        }
        if (!Permission::covers((array) ($actor['permissions'] ?? []), Permission::decode($role['permissions']))) {
            return 'You cannot delete a role that has more access than you.';
        }
        $count = $this->roles->userCount((int) $role['id']);
        if ($count > 0) {
            return sprintf('The %s role is assigned to %d user%s. Move %s to another role first.', $role['name'], $count, $count === 1 ? '' : 's', $count === 1 ? 'them' : 'them all');
        }
        return null;
    }

    /**
     * @param array{name: string, description: string, permissions: array<int, string>} $data
     * @return array<string, mixed>
     */
    public function createRole(array $data): array
    {
        $id = $this->roles->create($data['name'], $data['description'], $data['permissions']);
        ActivityService::log('role.created', sprintf('Role created: %s (%d permission%s)', $data['name'], count($data['permissions']), count($data['permissions']) === 1 ? '' : 's'), null, [
            'permissions' => implode(', ', $data['permissions']),
        ]);
        return $this->roles->find($id) ?? [];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array{name: string, description: string, permissions: array<int, string>} $data
     * @return array<string, mixed>
     */
    public function updateRole(array $existing, array $data): array
    {
        $before = Permission::decode($existing['permissions']);
        $this->roles->update((int) $existing['id'], $data['name'], $data['description'], $data['permissions']);
        $added = array_values(array_diff($data['permissions'], $before));
        $removed = array_values(array_diff($before, $data['permissions']));
        $context = [];
        if ($added !== []) {
            $context['granted'] = implode(', ', $added);
        }
        if ($removed !== []) {
            $context['revoked'] = implode(', ', $removed);
        }
        if ($existing['name'] !== $data['name']) {
            $context['renamed'] = $existing['name'] . ' → ' . $data['name'];
        }
        ActivityService::log('role.updated', sprintf('Role updated: %s', $data['name']), null, $context ?: null);
        return $this->roles->find((int) $existing['id']) ?? [];
    }

    /** @param array<string, mixed> $role */
    public function deleteRole(array $role): void
    {
        $this->roles->delete((int) $role['id']);
        ActivityService::log('role.deleted', sprintf('Role deleted: %s', $role['name']));
    }

    /**
     * @param array<string, mixed> $role Row from RoleRepository::allWithCounts()
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function presentRole(array $role, array $actor): array
    {
        $permissions = Permission::decode($role['permissions']);
        $actorPermissions = (array) ($actor['permissions'] ?? []);
        $covered = Permission::covers($actorPermissions, $permissions);
        $users = (int) ($role['user_count'] ?? 0);
        return [
            'id'            => (int) $role['id'],
            'name'          => (string) $role['name'],
            'slug'          => (string) $role['slug'],
            'description'   => (string) $role['description'],
            'is_system'     => (int) $role['is_system'] === 1,
            'full_access'   => in_array(Permission::ALL, $permissions, true),
            'permissions'   => Permission::expand($permissions),
            'user_count'    => $users,
            'active_count'  => (int) ($role['active_count'] ?? 0),
            'assignable'    => $covered,
            'can_edit'      => (int) $role['is_system'] !== 1 && $covered,
            'can_delete'    => (int) $role['is_system'] !== 1 && $covered && $users === 0,
            'updated_label' => format_date($role['updated_at'] ?? null),
        ];
    }

    /**
     * Permission catalogue grouped for the role editor.
     *
     * @return array<int, array{group: string, permissions: array<int, array{key: string, label: string, description: string}>}>
     */
    public static function permissionCatalogue(): array
    {
        $out = [];
        foreach (Permission::GROUPS as $group => $permissions) {
            $items = [];
            foreach ($permissions as $key => [$label, $description]) {
                $items[] = ['key' => $key, 'label' => $label, 'description' => $description];
            }
            $out[] = ['group' => $group, 'permissions' => $items];
        }
        return $out;
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = strtoupper(mb_substr((string) ($parts[0] ?? ''), 0, 1));
        if (count($parts) > 1) {
            $initials .= strtoupper(mb_substr((string) end($parts), 0, 1));
        }
        return $initials !== '' ? $initials : '?';
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $user */
    private function removesLastAdministrator(array $user, int $newRoleId, bool $staysActive): bool
    {
        $adminRole = $this->roles->findBySlug(RoleRepository::ADMINISTRATOR);
        if ($adminRole === null || (int) $user['role_id'] !== (int) $adminRole['id'] || (int) $user['is_active'] !== 1) {
            return false;
        }
        if ($newRoleId === (int) $adminRole['id'] && $staysActive) {
            return false;
        }
        return $this->users->countActiveWithRole((int) $adminRole['id']) <= 1;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
