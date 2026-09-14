<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Process lock built on flock(). The operating system releases the lock automatically if the
 * holding process dies, so a crashed cron run never leaves a stale lock behind. The lock file
 * also records pid + timestamp for diagnostics.
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;
    private bool $acquired = false;

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['pid' => getmypid(), 'started_at' => gmdate('Y-m-d H:i:s')]));
        fflush($handle);
        $this->handle = $handle;
        $this->acquired = true;
        return true;
    }

    public function isAcquired(): bool
    {
        return $this->acquired;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
        $this->acquired = false;
    }

    /**
     * Information about the current lock holder (if any), for diagnostics.
     *
     * @return array{pid?: int, started_at?: string}|null
     */
    public function info(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
