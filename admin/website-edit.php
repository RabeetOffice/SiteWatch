<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_permission('websites.manage');

use App\Core\App;
use App\Core\Response;
use App\Services\ServiceFactory;

App::auth()->requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$website = $id > 0 ? ServiceFactory::websites()->find($id) : null;
if ($website === null) {
    App::session()->flash('toast', ['message' => 'Website not found.', 'type' => 'danger']);
    Response::redirect(base_url('admin/websites.php'));
}

$pageTitle = 'Edit Website';
$pageContext = '<span>' . e($website['name']) . '</span><span>' . e($website['domain']) . '</span>';
$activeNav = 'websites';
$pageScripts = ['website-form.js'];
$headerActions = '<a href="' . e(base_url('admin/website-details.php?id=' . $id)) . '" class="btn btn-light"><i class="bi bi-arrow-left"></i>Back to details</a>';
$formMode = 'edit';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/website-form.php';
require dirname(__DIR__) . '/includes/footer.php';
