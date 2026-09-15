<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\WebsiteRepository;
use App\Services\ServiceFactory;

// CSV template download (GET) does not change state.
if (Request::method() === 'GET' && Request::bool('template')) {
    Api::boot(['GET'], permission: 'websites.manage');
    Response::csv('sitewatch-import-template.csv', ['Website Name', 'Client Name', 'URL', 'Type', 'Check Interval'], [
        ['Northern Star Press', 'Northern Star Press Ltd', 'https://northernstarpress.co.uk', 'wordpress', 5],
        ['Example Shop', 'Example Retail', 'example-shop.com', 'woocommerce', 2],
    ]);
}

Api::boot(['POST'], permission: 'websites.manage');

$service = ServiceFactory::websiteService();
$mode = Request::string('mode', 'paste');
$entries = [];
$parseErrors = [];

if ($mode === 'csv' || isset($_FILES['file'])) {
    $file = $_FILES['file'] ?? null;
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $message = match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
            UPLOAD_ERR_NO_FILE => 'Please choose a CSV file to upload.',
            default => 'The file upload failed (error ' . $code . ').',
        };
        Response::error($message, ['file' => $message], 422);
    }
    if ((int) $file['size'] > 2 * 1024 * 1024) {
        Response::error('The CSV file must be smaller than 2 MB.', ['file' => 'File too large.'], 422);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        Response::error('Invalid upload.', [], 400);
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt'], true)) {
        Response::error('Only .csv files are accepted.', ['file' => 'Unsupported file type.'], 422);
    }
    $mime = (string) (mime_content_type($file['tmp_name']) ?: '');
    if ($mime !== '' && !preg_match('#^(text/|application/(csv|vnd\.ms-excel|octet-stream))#', $mime)) {
        Response::error('The file does not look like a CSV text file.', ['file' => 'Unsupported content type: ' . $mime], 422);
    }
    $content = (string) file_get_contents($file['tmp_name']);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', 'Windows-1252, ISO-8859-1');
        $content = is_string($converted) ? $converted : $content;
    }
    if (substr_count($content, "\n") > 5000) {
        Response::error('The CSV file contains too many rows (maximum 5000).', [], 422);
    }
    $parsed = $service->parseCsv($content);
    $entries = $parsed['entries'];
    $parseErrors = $parsed['errors'];
} else {
    $text = (string) Request::input('urls', '');
    if (trim($text) === '') {
        Response::error('Paste at least one URL.', ['urls' => 'No URLs provided.'], 422);
    }
    if (substr_count($text, "\n") > 2000) {
        Response::error('Too many lines (maximum 2000 per import).', [], 422);
    }
    $type = Request::string('type', 'wordpress');
    $interval = Request::int('check_interval', App::settings()->getInt('default_check_interval', 5));
    $client = mb_substr(Request::string('client_name'), 0, 150);
    foreach ($service->parseUrlList($text) as $entry) {
        $entry['type'] = in_array($type, WebsiteRepository::TYPES, true) ? $type : 'wordpress';
        $entry['check_interval'] = $interval;
        if ($entry['client_name'] === '') {
            $entry['client_name'] = $client;
        }
        $entries[] = $entry;
    }
}

if ($parseErrors !== []) {
    Response::error(implode(' ', $parseErrors), ['file' => $parseErrors[0]], 422);
}
if ($entries === []) {
    Response::error('No website entries were found in the input.', [], 422);
}

set_time_limit(300);
$report = $service->import($entries, App::auth()->id(), Request::ip());

$imported = count($report['imported']);
$message = sprintf('%d imported, %d duplicate%s, %d invalid, %d failed.', $imported, count($report['duplicates']), count($report['duplicates']) === 1 ? '' : 's', count($report['invalid']), count($report['failed']));

Response::success($message, [
    'report' => $report,
    'counts' => ['imported' => $imported, 'duplicates' => count($report['duplicates']), 'invalid' => count($report['invalid']), 'failed' => count($report['failed']), 'total' => count($entries)],
]);
