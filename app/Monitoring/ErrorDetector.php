<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Detects WordPress / PHP / server failure pages inside a response body.
 *
 * Detection is deliberately conservative. Every signature has a matching mode:
 *
 *   strict  - unambiguous markup such as <title>500 Internal Server Error</title>; matches anywhere.
 *   context - text that a blog article could legitimately quote ("There has been a critical error on
 *             this website"). Accepted only when the response looks like an error page: small body,
 *             HTTP 5xx, or WordPress error-page markup (id="error-page", wp-die-message).
 *   edge    - PHP fatal error output. Accepted in error-page context, or when the match sits at the
 *             very start / end of the output (where PHP prints fatal errors) and is not inside a
 *             <pre>/<code> sample.
 */
final class ErrorDetector
{
    public const CATEGORY_WP_CRITICAL = 'wp_errors';
    public const CATEGORY_DATABASE = 'database';
    public const CATEGORY_MAINTENANCE = 'maintenance';
    public const CATEGORY_FATAL = 'fatal_errors';
    public const CATEGORY_SERVER = 'http';

    private const MODE_STRICT = 'strict';
    private const MODE_CONTEXT = 'context';
    private const MODE_EDGE = 'edge';

    /** Bodies up to this size are treated as potential error pages (real WordPress error pages are ~2 KB). */
    private const ERROR_PAGE_MAX_BYTES = 16384;
    private const EDGE_BYTES = 3072;

    /**
     * Ordered signature list. First match wins, so the most specific WordPress signatures come first.
     *
     * @var array<int, array{pattern: string, status: string, type: string, message: string, category: string, mode: string}>
     */
    private const SIGNATURES = [
        // --- WordPress critical error (wp_die fatal handler, WP 5.2+) ---
        ['pattern' => '/There has been a critical error on (this|your) website/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'wp_critical_error', 'message' => 'WordPress Critical Error: "There has been a critical error on this website"', 'category' => self::CATEGORY_WP_CRITICAL, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/Es gab einen kritischen Fehler auf (dieser|deiner) Website/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'wp_critical_error', 'message' => 'WordPress Critical Error (German error page detected)', 'category' => self::CATEGORY_WP_CRITICAL, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/Ha habido un error cr[ií]tico en (esta|tu) web/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'wp_critical_error', 'message' => 'WordPress Critical Error (Spanish error page detected)', 'category' => self::CATEGORY_WP_CRITICAL, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/Il y a eu une erreur critique sur (ce|votre) site/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'wp_critical_error', 'message' => 'WordPress Critical Error (French error page detected)', 'category' => self::CATEGORY_WP_CRITICAL, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/Si è verificato un errore critico sul (tuo )?sito web/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'wp_critical_error', 'message' => 'WordPress Critical Error (Italian error page detected)', 'category' => self::CATEGORY_WP_CRITICAL, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/Er (is|was) een kritieke fout opgetreden op (deze|je) (website|site)/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'wp_critical_error', 'message' => 'WordPress Critical Error (Dutch error page detected)', 'category' => self::CATEGORY_WP_CRITICAL, 'mode' => self::MODE_CONTEXT],

        // --- WordPress database errors ---
        ['pattern' => '/Error establishing a database connection/i', 'status' => Status::DATABASE_ERROR, 'type' => 'wp_database_error', 'message' => 'Error establishing a database connection', 'category' => self::CATEGORY_DATABASE, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/<title>\s*Database Error\s*<\/title>/i', 'status' => Status::DATABASE_ERROR, 'type' => 'wp_database_error', 'message' => 'WordPress "Database Error" page detected', 'category' => self::CATEGORY_DATABASE, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/One or more database tables are unavailable/i', 'status' => Status::DATABASE_ERROR, 'type' => 'wp_database_error', 'message' => 'WordPress reports that database tables are unavailable (repair required)', 'category' => self::CATEGORY_DATABASE, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/Can\'?t select database/i', 'status' => Status::DATABASE_ERROR, 'type' => 'wp_database_error', 'message' => 'WordPress cannot select the database', 'category' => self::CATEGORY_DATABASE, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/SQLSTATE\[HY000\] \[(1045|2002|2006)\]/i', 'status' => Status::DATABASE_ERROR, 'type' => 'database_error', 'message' => 'Database connection error exposed on page', 'category' => self::CATEGORY_DATABASE, 'mode' => self::MODE_EDGE],
        ['pattern' => '/Access denied for user \'[^\']*\'@\'[^\']*\' \(using password: (YES|NO)\)/i', 'status' => Status::DATABASE_ERROR, 'type' => 'database_error', 'message' => 'Database access denied error exposed on page', 'category' => self::CATEGORY_DATABASE, 'mode' => self::MODE_EDGE],

        // --- WordPress maintenance mode (.maintenance file) ---
        ['pattern' => '/Briefly unavailable for scheduled maintenance/i', 'status' => Status::MAINTENANCE, 'type' => 'wp_maintenance', 'message' => 'WordPress maintenance mode: "Briefly unavailable for scheduled maintenance"', 'category' => self::CATEGORY_MAINTENANCE, 'mode' => self::MODE_CONTEXT],
        ['pattern' => '/<title>[^<]{0,60}\b(under|scheduled|in) maintenance\b[^<]{0,60}<\/title>/i', 'status' => Status::MAINTENANCE, 'type' => 'maintenance_page', 'message' => 'Maintenance page detected', 'category' => self::CATEGORY_MAINTENANCE, 'mode' => self::MODE_CONTEXT],

        // --- Exposed PHP fatal errors ---
        ['pattern' => '/(<b>|\b)Fatal error(<\/b>)?:\s+(Uncaught|Allowed memory|Maximum execution|Call to|Cannot|Class|require|Declaration|Unsupported|Interface)/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'php_fatal_error', 'message' => 'PHP fatal error exposed on the page', 'category' => self::CATEGORY_FATAL, 'mode' => self::MODE_EDGE],
        ['pattern' => '/PHP Fatal error:\s+/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'php_fatal_error', 'message' => 'PHP fatal error exposed on the page', 'category' => self::CATEGORY_FATAL, 'mode' => self::MODE_EDGE],
        ['pattern' => '/(<b>|\b)Parse error(<\/b>)?:\s+syntax error/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'php_parse_error', 'message' => 'PHP parse error exposed on the page', 'category' => self::CATEGORY_FATAL, 'mode' => self::MODE_EDGE],
        ['pattern' => '/Uncaught (Error|Exception|TypeError|ArgumentCountError|ValueError|DivisionByZeroError|ParseError)\b/', 'status' => Status::CRITICAL_ERROR, 'type' => 'php_uncaught_error', 'message' => 'Uncaught PHP error exposed on the page', 'category' => self::CATEGORY_FATAL, 'mode' => self::MODE_EDGE],
        ['pattern' => '/Allowed memory size of \d+ bytes exhausted/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'php_memory_exhausted', 'message' => 'PHP memory limit exhausted', 'category' => self::CATEGORY_FATAL, 'mode' => self::MODE_EDGE],
        ['pattern' => '/Maximum execution time of \d+ seconds exceeded/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'php_execution_timeout', 'message' => 'PHP maximum execution time exceeded', 'category' => self::CATEGORY_FATAL, 'mode' => self::MODE_EDGE],
        ['pattern' => '/Call to undefined (function|method) [A-Za-z0-9_\\\\:>-]+\(\)/i', 'status' => Status::CRITICAL_ERROR, 'type' => 'php_undefined_function', 'message' => 'PHP "Call to undefined function" error exposed on the page', 'category' => self::CATEGORY_FATAL, 'mode' => self::MODE_EDGE],

        // --- Generic server error pages (proxy / Apache / nginx) ---
        ['pattern' => '/<title>\s*(500\s*[-–|]?\s*)?Internal Server Error\s*<\/title>/i', 'status' => Status::HTTP_500, 'type' => 'server_error_page', 'message' => 'Internal Server Error page returned', 'category' => self::CATEGORY_SERVER, 'mode' => self::MODE_STRICT],
        ['pattern' => '/<h1>\s*(500\s*[-–|]?\s*)?Internal Server Error\s*<\/h1>/i', 'status' => Status::HTTP_500, 'type' => 'server_error_page', 'message' => 'Internal Server Error page returned', 'category' => self::CATEGORY_SERVER, 'mode' => self::MODE_STRICT],
        ['pattern' => '/<title>\s*(503\s*[-–|]?\s*)?Service (Temporarily )?Unavailable\s*<\/title>/i', 'status' => Status::HTTP_503, 'type' => 'server_error_page', 'message' => 'Service Unavailable page returned', 'category' => self::CATEGORY_SERVER, 'mode' => self::MODE_STRICT],
        ['pattern' => '/<h1>\s*(503\s*[-–|]?\s*)?Service (Temporarily )?Unavailable\s*<\/h1>/i', 'status' => Status::HTTP_503, 'type' => 'server_error_page', 'message' => 'Service Unavailable page returned', 'category' => self::CATEGORY_SERVER, 'mode' => self::MODE_STRICT],
        ['pattern' => '/<(title|h1)>\s*(502\s*[-–|]?\s*)?Bad Gateway\s*<\/(title|h1)>/i', 'status' => Status::HTTP_502, 'type' => 'server_error_page', 'message' => 'Bad Gateway page returned', 'category' => self::CATEGORY_SERVER, 'mode' => self::MODE_STRICT],
        ['pattern' => '/<(title|h1)>\s*(504\s*[-–|]?\s*)?Gateway Time-?out\s*<\/(title|h1)>/i', 'status' => Status::HTTP_504, 'type' => 'server_error_page', 'message' => 'Gateway Timeout page returned', 'category' => self::CATEGORY_SERVER, 'mode' => self::MODE_STRICT],
    ];

    /**
     * @param array<int, string> $enabledCategories Categories to look for (empty = all).
     * @return array{status: string, type: string, message: string, category: string, signature: string, excerpt: string}|null
     */
    public function detect(string $body, ?int $httpStatus = null, array $enabledCategories = []): ?array
    {
        if ($body === '') {
            return null;
        }
        $length = strlen($body);
        $isErrorPageContext = $length <= self::ERROR_PAGE_MAX_BYTES
            || ($httpStatus !== null && $httpStatus >= 500)
            || stripos($body, 'id="error-page"') !== false
            || stripos($body, 'class="wp-die-message"') !== false;

        foreach (self::SIGNATURES as $signature) {
            if ($enabledCategories !== [] && !in_array($signature['category'], $enabledCategories, true)) {
                continue;
            }
            if (!preg_match($signature['pattern'], $body, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $offset = (int) $match[0][1];

            if ($signature['mode'] === self::MODE_CONTEXT && !$isErrorPageContext) {
                continue;
            }
            if ($signature['mode'] === self::MODE_EDGE && !$isErrorPageContext) {
                // PHP prints fatal errors before the page starts, after the page ends, or at the point
                // where rendering stopped (truncated output). Text inside <body> is page content.
                if (!$this->isOutsidePageContent($body, $offset, $length) || $this->insideCodeBlock($body, $offset)) {
                    continue;
                }
            }
            if ($signature['mode'] !== self::MODE_STRICT && $isErrorPageContext && $length > 2048 && $this->insideCodeBlock($body, $offset)) {
                continue; // documentation sample inside a small page
            }

            return [
                'status'    => $signature['status'],
                'type'      => $signature['type'],
                'message'   => $signature['message'],
                'category'  => $signature['category'],
                'signature' => $signature['pattern'],
                'excerpt'   => $this->excerpt($body, $offset),
            ];
        }
        return null;
    }

    /**
     * Is the WordPress markup present? Used for diagnostics only.
     */
    public function looksLikeWordPress(string $body): bool
    {
        return stripos($body, '/wp-content/') !== false || stripos($body, '/wp-includes/') !== false
            || stripos($body, 'name="generator" content="WordPress') !== false;
    }

    /**
     * True when the match sits outside the rendered page content: before <body> (or <html>) opens,
     * after </body> closes, or near the end of output that was never closed (rendering aborted).
     */
    private function isOutsidePageContent(string $body, int $offset, int $length): bool
    {
        $pageOpen = stripos($body, '<!doctype');
        if ($pageOpen === false) {
            $pageOpen = stripos($body, '<html');
        }
        if ($pageOpen === false) {
            $pageOpen = stripos($body, '<body');
        }
        if ($pageOpen === false) {
            // No HTML document structure at all: treat as raw output and check the edges.
            return $offset <= self::EDGE_BYTES || $offset >= $length - self::EDGE_BYTES;
        }
        if ($offset < $pageOpen) {
            return true; // printed before the document started
        }
        $pageClose = strripos($body, '</html>');
        if ($pageClose === false) {
            $pageClose = strripos($body, '</body>');
        }
        if ($pageClose !== false) {
            return $offset > $pageClose; // printed after the document ended (shutdown / late plugin code)
        }
        // Output was cut off before the document closed: a fatal error near the end is the likely cause.
        return $offset >= $length - self::EDGE_BYTES;
    }

    private function insideCodeBlock(string $body, int $offset): bool
    {
        $before = substr($body, max(0, $offset - 600), min(600, $offset));
        $lastOpen = max((int) strripos($before, '<pre'), (int) strripos($before, '<code'));
        $hasOpen = strripos($before, '<pre') !== false || strripos($before, '<code') !== false;
        $lastClose = max((int) strripos($before, '</pre>'), (int) strripos($before, '</code>'));
        $hasClose = strripos($before, '</pre>') !== false || strripos($before, '</code>') !== false;
        return $hasOpen && (!$hasClose || $lastOpen > $lastClose);
    }

    private function excerpt(string $body, int $offset): string
    {
        $start = max(0, $offset - 60);
        $snippet = substr($body, $start, 220);
        $snippet = strip_tags($snippet);
        $snippet = preg_replace('/\s+/', ' ', $snippet) ?? $snippet;
        return trim(mb_substr($snippet, 0, 200));
    }
}
