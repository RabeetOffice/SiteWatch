<?php

declare(strict_types=1);

/**
 * A saved client report for signed-in users, whatever the state of its share link.
 *
 *   ?id=<id>               download the PDF
 *   ?id=<id>&format=html   preview the report page (the same page the share link shows)
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\BrandLogoService;
use App\Services\ClientReportRenderer;
use App\Services\ClientReportService;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'reports.view');
App::session()->release();

$service = ServiceFactory::clientReports();
$report = $service->reports()->find(Request::int('id'));
if ($report === null) {
    Response::error('Report not found.', [], 404);
}
$brand = $service->brandFor($report);
$renderer = new ClientReportRenderer();
$data = $service->build($report);

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (Request::string('format') === 'html') {
    $nonce = bin2hex(random_bytes(16));
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'self'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    echo $renderer->html($data, 'web', [
        'logo_src' => (int) $brand['id'] > 0 ? ClientReportService::presentBrand($brand)['logo_url'] : null,
        'pdf_url'  => base_url('api/client-reports/pdf.php') . '?id=' . (int) $report['id'],
        'nonce'    => $nonce,
        'font_url' => asset('fonts/inter-latin-wght-normal.woff2'),
    ]);
    exit;
}

try {
    $bytes = $renderer->pdf($data, BrandLogoService::create()->dataUri($brand['logo'] ?? null));
} catch (\Throwable $e) {
    App::logger('app')->error('Client report PDF failed', ['report' => (int) $report['id'], 'error' => $e->getMessage()]);
    Response::error('The PDF could not be created: ' . $e->getMessage(), [], 500);
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . ClientReportRenderer::fileName((string) $brand['name'], (string) $report['title'], $data['range']) . '"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
