<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Response helpers: JSON envelopes, redirects and CSV streaming.
 *
 * JSON envelope:
 *   { "success": true,  "message": "...", "data": {...} }
 *   { "success": false, "message": "...", "errors": {...} }
 */
final class Response
{
    public static function json(mixed $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    /** @param array<string, mixed>|object $data */
    public static function success(string $message = '', array|object $data = [], int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    /** @param array<string, mixed> $errors */
    public static function error(string $message, array $errors = [], int $status = 400): never
    {
        self::json(['success' => false, 'message' => $message, 'errors' => (object) $errors], $status);
    }

    public static function redirect(string $url, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        } else {
            echo '<script>window.location.href=' . json_out($url) . ';</script>';
        }
        exit;
    }

    /**
     * Stream a CSV download. Every cell is escaped by fputcsv and formula-injection prefixes are neutralised.
     *
     * @param array<int, string> $headers
     * @param iterable<int, array<int, mixed>> $rows
     */
    public static function csv(string $filename, array $headers, iterable $rows): never
    {
        $filename = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $filename) ?: 'export.csv';
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        fputcsv($out, array_map([self::class, 'csvCell'], $headers), ',', '"', '\\', "\r\n");
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'csvCell'], $row), ',', '"', '\\', "\r\n");
        }
        fclose($out);
        exit;
    }

    public static function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $value = (string) $value;
        // Prevent CSV/formula injection when opened in spreadsheet software.
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'" . $value;
        }
        return $value;
    }
}
