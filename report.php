<?php

declare(strict_types=1);

/**
 * Public client report: the page behind a share link, for people without a SiteWatch account.
 *
 *   /r/<token>          the branded report page     (report.php?t=<token>)
 *   /r/<token>.pdf      the same report as a PDF    (report.php?t=<token>&pdf=.pdf)
 *   report.php?t=<token>&asset=logo   the brand logo shown on the page
 *
 * The token is the only credential: a link that is switched off, expired or regenerated answers 404. No session is
 * started, the page sends no referrer (the token is in the address) and asks search engines not to index it.
 */

define('SW_STATELESS', true);
define('SW_ALLOW_PENDING_SCHEMA', true);
require __DIR__ . '/bootstrap.php';

use App\Core\App;
use App\Services\BrandLogoService;
use App\Services\ClientReportRenderer;
use App\Services\ClientReportService;
use App\Services\ServiceFactory;

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Report not available</title></head>'
        . '<body style="margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#EEF2F6;color:#0F172A;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px">'
        . '<div style="max-width:440px;background:#fff;border-radius:16px;padding:32px;box-shadow:0 12px 40px rgba(15,23,42,.08);text-align:center">'
        . '<div style="font-size:40px;line-height:1">&#128196;</div><h1 style="font-size:20px;margin:12px 0 8px">This report is not available</h1>'
        . '<p style="margin:0;color:#64748B;line-height:1.5">The link may have expired or been switched off. Please ask the person who sent it for a new link.</p>'
        . '</div></body></html>';
    exit;
};

$token = (string) ($_GET['t'] ?? '');
try {
    $service = ServiceFactory::clientReports();
    $report = $service->reports()->findByToken($token);
} catch (\Throwable $e) {
    // Tables missing while a database update waits, or the database is down.
    App::logger('app')->warning('Client report unavailable', ['error' => $e->getMessage()]);
    $notFound();
}
if ($report === null || ClientReportService::linkStatus($report) !== 'active') {
    $notFound();
}

$brand = $service->brandFor($report);
$logos = BrandLogoService::create();

if (($_GET['asset'] ?? '') === 'logo') {
    $path = $logos->resolve($brand['logo'] ?? null);
    if ($path === null) {
        $notFound();
    }
    $logos->serve($path, (string) $brand['logo'], true);
}

$renderer = new ClientReportRenderer();

if (($_GET['pdf'] ?? '') !== '' || ($_GET['format'] ?? '') === 'pdf') {
    // A PDF takes a moment to render, so a copy is kept for ten minutes; repeated downloads reuse it.
    $cacheDir = rtrim((string) App::config()->get('app.paths.cache'), '/\\') . DIRECTORY_SEPARATOR . 'client-reports';
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . sha1($report['token'] . '|' . $report['updated_at'] . '|' . ($brand['updated_at'] ?? '')) . '.pdf';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 600) {
        $bytes = (string) file_get_contents($cacheFile);
    } else {
        try {
            $bytes = $renderer->pdf($service->build($report), $logos->dataUri($brand['logo'] ?? null));
        } catch (\Throwable $e) {
            App::logger('app')->error('Client report PDF failed', ['report' => (int) $report['id'], 'error' => $e->getMessage()]);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'The PDF could not be created. Please try again later.';
            exit;
        }
        if (is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) {
            foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $old) {
                if (filemtime($old) < time() - 600) {
                    @unlink($old);
                }
            }
            @file_put_contents($cacheFile, $bytes, LOCK_EX);
        }
    }
    $range = $service->periodRange($report);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . ClientReportRenderer::fileName((string) $brand['name'], (string) $report['title'], $range) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
}

$service->reports()->recordView((int) $report['id']);

$nonce = bin2hex(random_bytes(16));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'self'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

echo $renderer->html($service->build($report), 'web', [
    'logo_src' => !empty($brand['logo']) ? base_url('report.php') . '?t=' . rawurlencode($report['token']) . '&asset=logo&v=' . rawurlencode(pathinfo((string) $brand['logo'], PATHINFO_FILENAME)) : null,
    'pdf_url'  => ClientReportService::shareUrl((string) $report['token'], true),
    'nonce'    => $nonce,
    'font_url' => asset('fonts/inter-latin-wght-normal.woff2'),
]);
