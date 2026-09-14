<?php

declare(strict_types=1);

/**
 * Application bootstrap. Included by every web page, API endpoint and cron script.
 *
 *   define('SW_API', true);        // before including: JSON error output
 *   define('SW_INSTALLER', true);  // before including: skip the "is installed" guard
 */

use App\Core\App;
use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\Response;
use Dotenv\Dotenv;

define('SW_START', microtime(true));
define('SW_ROOT', __DIR__);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SiteWatch: Composer dependencies are missing.\nRun `composer install --no-dev --optimize-autoloader` inside the project directory.\n";
    exit(1);
}
require $autoload;

// Environment ----------------------------------------------------------------
if (is_file(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

// All internal date handling is UTC. Display conversion happens in helpers.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$config = new Config(__DIR__ . '/config');
App::boot($config);

ErrorHandler::register((bool) $config->get('app.debug', false), defined('SW_API'));

// Make sure writable storage directories exist.
foreach (['storage', 'logs', 'cache', 'locks'] as $dirKey) {
    $dir = (string) $config->get('app.paths.' . $dirKey);
    if ($dir !== '' && !is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Installation guard -----------------------------------------------------------
if (!defined('SW_INSTALLER') && !App::isInstalled()) {
    if (App::isCli()) {
        fwrite(STDERR, "SiteWatch is not installed yet. Open install.php in your browser first.\n");
        exit(1);
    }
    Response::redirect(App::detectBaseUrl() . '/install.php');
}

// Web request setup ------------------------------------------------------------
if (!App::isCli()) {
    App::session()->start();

    if (!headers_sent()) {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (!defined('SW_API')) {
            $nonce = App::session()->get('_csp_nonce');
            if (!is_string($nonce) || $nonce === '') {
                $nonce = bin2hex(random_bytes(16));
                App::session()->set('_csp_nonce', $nonce);
            }
            define('SW_CSP_NONCE', $nonce);
            header(
                "Content-Security-Policy: default-src 'self'; "
                . "script-src 'self' https://cdn.jsdelivr.net 'nonce-{$nonce}'; "
                . "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; "
                . "font-src 'self' https://cdn.jsdelivr.net data:; "
                . "img-src 'self' data: https:; "
                . "connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'"
            );
        }
    }
}
