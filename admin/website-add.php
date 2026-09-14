<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$pageTitle = 'Add Website';
$activeNav = 'website-add';
$pageScripts = ['website-form.js'];
$headerActions = '<a href="' . e(base_url('admin/website-import.php')) . '" class="btn btn-light"><i class="bi bi-upload"></i>Import many</a>';

$website = null;
$formMode = 'create';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/website-form.php';
require dirname(__DIR__) . '/includes/footer.php';
