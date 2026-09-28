<?php

declare(strict_types=1);

/**
 * Create or update a report brand kit. Multipart form: the brand fields, plus an optional `logo` file or
 * `remove_logo=1`.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\BrandLogoService;
use App\Services\ClientReportService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'reports.share');

$service = ServiceFactory::clientReports();
$brands = $service->brands();
$logos = BrandLogoService::create();

$id = Request::int('id');
$existing = $id > 0 ? $brands->find($id) : null;
if ($id > 0 && $existing === null) {
    Response::error('Brand not found.', [], 404);
}

$validated = $service->validateBrand(Request::all());

// Check the upload before saving anything, so a bad logo does not leave a half-saved brand.
$file = $_FILES['logo'] ?? null;
$hasUpload = is_array($file) && !is_array($file['error'] ?? null) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($hasUpload) {
    $error = match ((int) $file['error']) {
        UPLOAD_ERR_OK => is_uploaded_file((string) $file['tmp_name']) ? null : 'The upload did not complete. Please try again.',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The logo is larger than this server accepts.',
        default => 'The upload did not complete. Please try again.',
    };
    if ($error !== null) {
        $validated['errors']['logo'] = $error;
    }
}
if ($validated['errors'] !== []) {
    Response::error('Please correct the highlighted fields.', $validated['errors'], 422);
}

if ($existing === null) {
    $id = $brands->create($validated['data']);
} else {
    $brands->update($id, $validated['data']);
}

$current = $existing['logo'] ?? null;
if ($hasUpload) {
    try {
        $name = $logos->store($id, (string) $file['tmp_name']);
    } catch (RuntimeException $e) {
        if ($existing === null) {
            $brands->delete($id);
        }
        Response::error($e->getMessage(), ['logo' => $e->getMessage()], 422);
    }
    $brands->update($id, ['logo' => $name]);
    if ($current !== null && $current !== $name) {
        $logos->delete($current);
    }
} elseif (Request::bool('remove_logo') && $current !== null) {
    $brands->update($id, ['logo' => null]);
    $logos->delete($current);
}

ActivityService::log('report.brand', sprintf('%s the report brand "%s"', $existing === null ? 'Created' : 'Updated', $validated['data']['name']));

$brand = $brands->find($id);
Response::success($existing === null ? 'Brand created.' : 'Brand saved.', ['brand' => ClientReportService::presentBrand((array) $brand)]);
