<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

abstract class BaseRepository
{
    public function __construct(protected readonly Database $db)
    {
    }

    protected function now(): string
    {
        return utc_now()->format('Y-m-d H:i:s');
    }

    /**
     * Convert an SQL LIKE search term into a safe pattern.
     */
    protected function like(string $term): string
    {
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        return '%' . $term . '%';
    }

    /**
     * @param array<int, int|string> $values
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function in(array $values, string $prefix = 'in'): array
    {
        return $this->db->inClause($values, $prefix);
    }
}
