<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. Connects lazily, forces UTC on the connection and provides small
 * helpers so repositories never build unsafe SQL.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = self::connect($this->config);
        }
        return $this->pdo;
    }

    /**
     * Create a raw PDO connection from a config array (also used by the installer).
     *
     * @param array<string, mixed> $config
     */
    public static function connect(array $config, bool $selectDatabase = true): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 3306),
            $config['charset'] ?? 'utf8mb4'
        );
        if ($selectDatabase && !empty($config['name'])) {
            $dsn .= ';dbname=' . $config['name'];
        }
        $options = $config['options'] ?? [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), $options);
        } catch (PDOException $e) {
            // Never leak credentials in the message.
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }
        // All timestamps are stored and compared in UTC.
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        return $pdo;
    }

    /** @param array<int|string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @param array<int|string, mixed> $params */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn($column);
        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, mixed>
     */
    public function fetchColumnAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map(fn ($c) => $this->quoteIdentifier($c), $columns)),
            implode(', ', array_map(fn ($c) => ':' . $c, $columns))
        );
        $this->query($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $sets[] = $this->quoteIdentifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->quoteIdentifier($table), implode(', ', $sets), $where);
        return $this->query($sql, array_merge($params, $whereParams))->rowCount();
    }

    /** @param array<string, mixed> $params */
    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->query(sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), $where), $params)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @template T
     * @param callable(Database): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $nested = $pdo->inTransaction();
        if (!$nested) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback($this);
            if (!$nested) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if (!$nested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Build a placeholder list for IN (...) clauses.
     *
     * @param array<int, int|string> $values
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function inClause(array $values, string $prefix = 'in'): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $key = $prefix . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        return [implode(', ', $placeholders) ?: 'NULL', $params];
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new RuntimeException('Invalid SQL identifier: ' . $identifier);
        }
        return '`' . $identifier . '`';
    }

    public function tableExists(string $table): bool
    {
        $row = $this->fetch('SHOW TABLES LIKE :t', ['t' => $table]);
        return $row !== null;
    }
}
