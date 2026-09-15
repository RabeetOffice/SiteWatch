<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.manage');

$id = Request::int('id');
$websites = ServiceFactory::websites();
$website = $id > 0 ? $websites->find($id) : null;
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

$service = ServiceFactory::websiteService();
$validated = $service->validate(Request::all(), $id);
if ($validated['errors'] !== []) {
    Response::error('Please correct the highlighted fields.', $validated['errors'], 422);
}

$updated = $service->update($website, $validated['data'], App::auth()->id(), Request::ip());

Response::success('Website updated.', [
    'website'  => $service->present($updated),
    'warnings' => $validated['warnings'],
    'redirect' => base_url('admin/website-details.php?id=' . $id),
]);
