<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\RoleRepository;
use RuntimeException;

/**
 * Versioned schema upgrades for existing installations.
 *
 * database/schema.sql always describes the latest schema (fresh installs); the steps below bring an older
 * database to the same shape. Every step inspects the current structure before changing it, so running the
 * migrator against an up-to-date database — e.g. straight after install — only seeds missing defaults.
 * Only structure changes: existing rows are kept and nothing is copied between installations.
 *
 * The applied version is stored in settings.schema_version and each run in settings.schema_history. Pending steps
 * are applied from admin/updates.php, by `php database/migrate.php`, or automatically when DB_AUTO_MIGRATE=true.
 * When adding a version, add both a step below and its description to STEPS.
 */
final class Migrator
{
    public const VERSION = 2;
    /** Version of databases created before schema versions were recorded. */
    public const BASELINE = 1;
    public const SETTING = 'schema_version';
    public const HISTORY = 'schema_history';

    /** What each version changes, shown on the Updates page before it is applied. */
    public const STEPS = [
        2 => [
            'title'   => 'User roles and domain & hosting details',
            'changes' => [
                'Creates the roles table with the built-in Administrator, Manager and Viewer roles.',
                'Links every user to a role — existing accounts become Administrators — and replaces the old users.role text column.',
                'Adds users.session_version, used to sign an account out everywhere after a password reset or deactivation.',
                'Creates the domain_info table for domain registration (WHOIS / RDAP) and hosting details.',
            ],
        ],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $schemaFile,
        private readonly string $lockPath
    ) {
    }

    public static function create(?Database $db = null): self
    {
        $config = App::config();
        return new self(
            $db ?? App::db(),
            (string) $config->get('app.paths.root') . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql',
            rtrim((string) $config->get('app.paths.locks'), '/\\') . DIRECTORY_SEPARATOR . 'migrate.lock'
        );
    }

    public function currentVersion(): int
    {
        return (int) ($this->db->fetchColumn('SELECT `value` FROM settings WHERE `key` = :k', ['k' => self::SETTING]) ?? self::BASELINE);
    }

    /**
     * Versions not applied to this database yet, with their descriptions.
     *
     * @return array<int, array{version: int, title: string, changes: array<int, string>}>
     */
    public function pending(): array
    {
        $current = $this->currentVersion();
        $pending = [];
        foreach (self::STEPS as $version => $step) {
            if ($version > $current) {
                $pending[] = ['version' => $version] + $step;
            }
        }
        return $pending;
    }

    /**
     * Applied updates, newest first.
     *
     * @return array<int, array{version: int, applied_at: string, duration_ms: int}>
     */
    public function history(): array
    {
        $list = json_decode((string) $this->db->fetchColumn('SELECT `value` FROM settings WHERE `key` = :k', ['k' => self::HISTORY]), true);
        return is_array($list) ? array_reverse(array_values(array_filter($list, 'is_array'))) : [];
    }

    /**
     * Apply pending migrations.
     *
     * @return array<int, int> Versions that were applied.
     */
    public function migrate(): array
    {
        if ($this->currentVersion() >= self::VERSION) {
            return [];
        }

        $lock = new Lock($this->lockPath);
        $deadline = microtime(true) + 30;
        while (!$lock->acquire()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('A database upgrade is already running. Please try again in a minute.');
            }
            usleep(250000);
        }

        try {
            // Another request may have finished the upgrade while we waited for the lock.
            $version = $this->currentVersion();
            $applied = [];
            foreach ($this->steps() as $target => $step) {
                if ($version >= $target) {
                    continue;
                }
                $started = microtime(true);
                $step();
                $this->setVersion($target, (int) round((microtime(true) - $started) * 1000));
                $version = $target;
                $applied[] = $target;
            }
            return $applied;
        } finally {
            $lock->release();
        }
    }

    /** @return array<int, callable(): void> */
    private function steps(): array
    {
        return [
            2 => fn () => $this->rolesAndDomainInfo(),
        ];
    }

    /**
     * v2: roles & permissions (users.role_id, users.session_version) and the domain_info table.
     */
    private function rolesAndDomainInfo(): void
    {
        $this->createTable('roles');
        $roles = new RoleRepository($this->db);
        $roles->seedDefaults();
        $adminRole = $roles->findBySlug(RoleRepository::ADMINISTRATOR);
        if ($adminRole === null) {
            throw new RuntimeException('Unable to create the Administrator role.');
        }

        if (!$this->columnExists('users', 'role_id')) {
            $this->db->pdo()->exec('ALTER TABLE `users` ADD COLUMN `role_id` INT UNSIGNED NULL AFTER `password_hash`');
        }
        // Every account created before roles existed was a full administrator.
        $this->db->query('UPDATE users SET role_id = :role WHERE role_id IS NULL', ['role' => (int) $adminRole['id']]);
        if ($this->columnExists('users', 'role')) {
            $this->db->pdo()->exec('ALTER TABLE `users` DROP COLUMN `role`');
        }
        if ($this->columnNullable('users', 'role_id')) {
            $this->db->pdo()->exec('ALTER TABLE `users` MODIFY `role_id` INT UNSIGNED NOT NULL');
        }
        if (!$this->constraintExists('users', 'fk_users_role')) {
            $this->db->pdo()->exec('ALTER TABLE `users` ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)');
        }
        if (!$this->columnExists('users', 'session_version')) {
            $this->db->pdo()->exec("ALTER TABLE `users` ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'incremented to sign the user out everywhere' AFTER `is_active`");
        }

        $this->createTable('domain_info');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Run the CREATE TABLE statement for $table from database/schema.sql, so the definition lives in one place.
     */
    private function createTable(string $table): void
    {
        $sql = is_file($this->schemaFile) ? (string) file_get_contents($this->schemaFile) : '';
        $pattern = '/CREATE TABLE IF NOT EXISTS `' . preg_quote($table, '/') . '` \(.*?\) ENGINE=[^;]+;/s';
        if (!preg_match($pattern, $sql, $match)) {
            throw new RuntimeException("database/schema.sql does not define the `{$table}` table.");
        }
        $this->db->pdo()->exec($match[0]);
    }

    private function setVersion(int $version, int $durationMs): void
    {
        $now = utc_now()->format('Y-m-d H:i:s');
        $history = array_reverse($this->history());
        $history[] = ['version' => $version, 'applied_at' => $now, 'duration_ms' => $durationMs];

        foreach ([self::SETTING => (string) $version, self::HISTORY => (string) json_encode(array_slice($history, -50))] as $key => $value) {
            $this->db->query(
                'INSERT INTO settings (`key`, `value`, updated_at) VALUES (:k, :v, :u)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
                ['k' => $key, 'v' => $value, 'u' => $now]
            );
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        ) > 0;
    }

    private function columnNullable(string $table, string $column): bool
    {
        return $this->db->fetchColumn(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        ) === 'YES';
    }

    private function constraintExists(string $table, string $name): bool
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n',
            ['t' => $table, 'n' => $name]
        ) > 0;
    }
}
