<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['POST']);

$id = Request::int('id');
$websites = ServiceFactory::websites();
$website = $id > 0 ? $websites->find($id) : null;
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

ServiceFactory::websiteService()->delete($website, App::auth()->id(), Request::ip());

Response::success('Website deleted.', ['id' => $id]);
