<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Converts PHP errors to exceptions, logs uncaught exceptions and renders safe error output.
 * Stack traces are only shown when APP_DEBUG=true.
 */
final class ErrorHandler
{
    private static bool $debug = false;
    private static bool $api = false;

    public static function register(bool $debug, bool $api = false): void
    {
        self::$debug = $debug;
        self::$api = $api;

        error_reporting(E_ALL);
        ini_set('display_errors', $debug && !$api ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function setApiMode(bool $api): void
    {
        self::$api = $api;
        if ($api) {
            ini_set('display_errors', '0');
        }
    }

    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false; // suppressed with @
        }
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE, E_WARNING, E_USER_WARNING], true)) {
            try {
                App::logger('app')->warning($message, ['file' => $file, 'line' => $line]);
            } catch (Throwable) {
                // ignore logging failures
            }
            // Warnings/notices are logged but do not abort the request.
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException(Throwable $e): void
    {
        try {
            App::logger('error')->error($e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => self::$debug ? $e->getTraceAsString() : substr($e->getTraceAsString(), 0, 2000),
            ]);
        } catch (Throwable) {
            // ignore logging failures
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n");
            if (self::$debug) {
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }
            exit(1);
        }

        if (self::$api || Request::isAjax()) {
            $payload = ['success' => false, 'message' => 'An unexpected server error occurred.', 'errors' => (object) []];
            if (self::$debug) {
                $payload['debug'] = ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
            }
            Response::json($payload, 500);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo self::renderErrorPage($e);
        exit(1);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::handleException(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        }
    }

    private static function renderErrorPage(Throwable $e): string
    {
        $details = '';
        if (self::$debug) {
            $details = '<pre class="details">' . e(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()) . '</pre>';
        }
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title>'
            . '<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#F8FAFC;color:#0F172A;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}'
            . '.box{background:#fff;border:1px solid #E5E7EB;border-radius:16px;padding:40px;max-width:720px;width:100%;box-shadow:0 1px 3px rgba(15,23,42,.06)}'
            . 'h1{font-size:22px;margin:0 0 8px}p{color:#64748B;margin:0 0 16px}.details{background:#0F172A;color:#E5E7EB;padding:16px;border-radius:12px;overflow:auto;font-size:12px;line-height:1.5}'
            . 'a{color:#6C5CE7;text-decoration:none;font-weight:600}</style></head><body><div class="box">'
            . '<h1>Something went wrong</h1><p>An unexpected error occurred. The problem has been logged. Please try again or contact the administrator.</p>'
            . $details . '<a href="javascript:history.back()">&larr; Go back</a></div></body></html>';
    }
}
