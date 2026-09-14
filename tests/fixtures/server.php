<?php

declare(strict_types=1);

/**
 * Fixture web server simulating WordPress / server failure scenarios.
 *
 *   php -S 127.0.0.1:8089 tests/fixtures/server.php
 *
 * Endpoints: /ok /404 /500 /502 /503 /504 /critical-500 /critical-200 /db-error /maintenance
 *            /fatal /blog-post /slow /very-slow /timeout /redirect-chain /redirect-loop /redirect-many
 *            /forbidden /server-error-page /empty /dynamic (/mode?set=ok|500|critical|db)
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $query);
$modeFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sitewatch-fixture-mode.txt';

$wpPage = static function (string $title, string $body): string {
    return "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>{$title}</title>"
        . "<meta name=\"generator\" content=\"WordPress 6.6\"><link rel=\"stylesheet\" href=\"/wp-content/themes/twentytwentyfour/style.css\"></head>"
        . "<body class=\"home\"><header><h1>Northern Star Press</h1></header><main>{$body}</main>"
        . "<script src=\"/wp-includes/js/jquery/jquery.min.js\"></script></body></html>";
};

$criticalPage = "<!DOCTYPE html><html lang=\"en-US\"><head><meta charset=\"utf-8\"><title>WordPress &rsaquo; Error</title></head>"
    . "<body id=\"error-page\"><div class=\"wp-die-message\"><p>There has been a critical error on this website.</p>"
    . "<p><a href=\"https://wordpress.org/documentation/article/faq-troubleshooting/\">Learn more about troubleshooting WordPress.</a></p></div></body></html>";

$dbErrorPage = "<!DOCTYPE html><html lang=\"en-US\"><head><meta charset=\"utf-8\"><title>Database Error</title></head>"
    . "<body id=\"error-page\"><div class=\"wp-die-message\"><h1>Error establishing a database connection</h1></div></body></html>";

$respond = static function (int $status, string $body, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo $body;
};

switch ($path) {
    case '/':
    case '/ok':
        $respond(200, $wpPage('Northern Star Press', '<p>Welcome to our website. Everything is fine here.</p>'));
        break;
    case '/404':
        $respond(404, $wpPage('Page not found', '<p>Nothing found.</p>'));
        break;
    case '/500':
        $respond(500, '<html><body><h1>Something broke</h1></body></html>');
        break;
    case '/502':
        $respond(502, '<html><head><title>502 Bad Gateway</title></head><body><h1>502 Bad Gateway</h1><hr><center>nginx</center></body></html>');
        break;
    case '/503':
        $respond(503, '<html><body>Temporarily overloaded</body></html>');
        break;
    case '/504':
        $respond(504, '<html><head><title>504 Gateway Time-out</title></head><body><h1>504 Gateway Time-out</h1></body></html>');
        break;
    case '/critical-500':
        $respond(500, $criticalPage);
        break;
    case '/critical-200':
        $respond(200, $criticalPage);
        break;
    case '/db-error':
        $respond(200, $dbErrorPage);
        break;
    case '/maintenance':
        $respond(503, '<!DOCTYPE html><html><head><title>Maintenance</title></head><body><h1>Briefly unavailable for scheduled maintenance. Check back in a minute.</h1></body></html>', ['Retry-After' => '600']);
        break;
    case '/fatal':
        $respond(200, "<br />\n<b>Fatal error</b>:  Uncaught Error: Call to undefined function wp_get_theme_json() in /home/site/public_html/wp-content/themes/custom/functions.php:42\nStack trace:\n#0 /home/site/public_html/wp-settings.php(600): include()\n#1 {main}\n  thrown in <b>/home/site/public_html/wp-content/themes/custom/functions.php</b> on line <b>42</b><br />\n");
        break;
    case '/blog-post':
        $filler = str_repeat('<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Curabitur vitae posuere lorem, ac maximus dolor.</p>', 260);
        $article = '<article><h1>How to fix "Fatal error: Allowed memory size exhausted" in WordPress</h1>' . $filler
            . '<p>If your site shows <code>Fatal error: Allowed memory size of 134217728 bytes exhausted</code> you need to raise the limit. '
            . 'Another common message is "Error establishing a database connection" which we cover in another article. Warning: back up first.</p>'
            . '<pre>PHP Fatal error:  Uncaught Error: Call to undefined function example()</pre>' . $filler . '</article>';
        $respond(200, $wpPage('Blog post about PHP errors', $article));
        break;
    case '/slow':
        usleep((int) (($query['ms'] ?? 1300) * 1000));
        $respond(200, $wpPage('Slow site', '<p>Sorry for the wait.</p>'));
        break;
    case '/very-slow':
        usleep((int) (($query['ms'] ?? 2800) * 1000));
        $respond(200, $wpPage('Very slow site', '<p>Really sorry for the wait.</p>'));
        break;
    case '/timeout':
        sleep((int) ($query['s'] ?? 6));
        $respond(200, $wpPage('Finally', '<p>Too late.</p>'));
        break;
    case '/redirect-chain':
        $respond(301, '', ['Location' => '/redirect-2']);
        break;
    case '/redirect-2':
        $respond(302, '', ['Location' => '/ok']);
        break;
    case '/redirect-loop':
        $respond(302, '', ['Location' => '/redirect-loop-b']);
        break;
    case '/redirect-loop-b':
        $respond(302, '', ['Location' => '/redirect-loop']);
        break;
    case '/redirect-many':
        $n = (int) ($query['n'] ?? 0);
        $respond(301, '', ['Location' => '/redirect-many?n=' . ($n + 1)]);
        break;
    case '/redirect-relative':
        $respond(307, '', ['Location' => 'ok']);
        break;
    case '/forbidden':
        $respond(403, '<html><body><h1>Access denied</h1><p>Bot protection active.</p></body></html>');
        break;
    case '/server-error-page':
        $respond(200, '<html><head><title>500 Internal Server Error</title></head><body><h1>Internal Server Error</h1></body></html>');
        break;
    case '/empty':
        $respond(200, '');
        break;
    case '/mode':
        $mode = (string) ($query['set'] ?? 'ok');
        file_put_contents($modeFile, $mode);
        $respond(200, 'mode=' . htmlspecialchars($mode));
        break;
    case '/dynamic':
        $mode = is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : 'ok';
        switch ($mode) {
            case '500':
                $respond(500, '<html><body>Internal error</body></html>');
                break;
            case 'critical':
                $respond(500, $criticalPage);
                break;
            case 'db':
                $respond(200, $dbErrorPage);
                break;
            case 'timeout':
                sleep(6);
                $respond(200, 'late');
                break;
            default:
                $respond(200, $wpPage('Dynamic', '<p>All good.</p>'));
        }
        break;
    default:
        $respond(404, '<html><body>Unknown fixture path</body></html>');
}
