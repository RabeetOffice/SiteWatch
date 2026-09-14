<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Starts the fixture web server (PHP built-in server) for scenario tests.
 */
final class FixtureServer
{
    /** @var resource|null */
    private $process = null;

    public function __construct(public readonly string $host = '127.0.0.1', public readonly int $port = 8089)
    {
    }

    public function baseUrl(): string
    {
        return 'http://' . $this->host . ':' . $this->port;
    }

    public function isRunning(): bool
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 1);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }
        $php = PHP_BINARY;
        $router = __DIR__ . DIRECTORY_SEPARATOR . 'server.php';
        $cmd = escapeshellarg($php) . ' -S ' . $this->host . ':' . $this->port . ' ' . escapeshellarg($router);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/sitewatch-fixture.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/sitewatch-fixture.log', 'a']];
        $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true, 'create_process_group' => true] : [];
        $this->process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2), null, $options);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Unable to start fixture server.');
        }
        for ($i = 0; $i < 50; $i++) {
            if ($this->isRunning()) {
                return;
            }
            usleep(100000);
        }
        throw new \RuntimeException('Fixture server did not start on ' . $this->baseUrl());
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (PHP_OS_FAMILY === 'Windows' && !empty($status['pid'])) {
                exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' >NUL 2>&1');
            } else {
                proc_terminate($this->process);
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function setMode(string $mode): void
    {
        @file_get_contents($this->baseUrl() . '/mode?set=' . rawurlencode($mode));
    }

    public function __destruct()
    {
        $this->stop();
    }
}
