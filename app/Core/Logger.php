<?php

declare(strict_types=1);

namespace App\Core;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Monolog factory. One rotating file per channel inside storage/logs:
 * app.log, monitor.log, cron.log, notifications.log, error.log
 */
final class Logger
{
    public static function create(string $channel, string $logDir, string $level = 'info', int $maxFiles = 14): MonologLogger
    {
        $logger = new MonologLogger($channel);
        $logger->pushProcessor(new PsrLogMessageProcessor());

        $monologLevel = Level::fromName(ucfirst(strtolower($level)) ?: 'Info');

        if ($logDir !== '' && (is_dir($logDir) || @mkdir($logDir, 0755, true)) && is_writable($logDir)) {
            $formatter = new LineFormatter("[%datetime%] %channel%.%level_name%: %message% %context%\n", 'Y-m-d H:i:s', true, true);

            $handler = new RotatingFileHandler($logDir . DIRECTORY_SEPARATOR . $channel . '.log', $maxFiles, $monologLevel);
            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);

            // Errors from every channel are mirrored into error.log for quick triage.
            if ($channel !== 'error') {
                $errorHandler = new RotatingFileHandler($logDir . DIRECTORY_SEPARATOR . 'error.log', $maxFiles, Level::Error);
                $errorHandler->setFormatter($formatter);
                $logger->pushHandler($errorHandler);
            }
        } else {
            $logger->pushHandler(new NullHandler());
        }

        return $logger;
    }
}
