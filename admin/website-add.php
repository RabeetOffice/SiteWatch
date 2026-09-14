<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$pageTitle = 'Add Website';
$pageSubtitle = 'Start monitoring a client website using only its public URL.';
$activeNav = 'website-add';
$pageScripts = ['website-form.js'];
$headerActions = '<a href="' . e(base_url('admin/website-import.php')) . '" class="btn btn-light"><i class="bi bi-upload"></i><span class="d-none d-sm-inline">Bulk Import</span></a>';

$website = null;
$formMode = 'create';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/website-form.php';
require dirname(__DIR__) . '/includes/footer.php';
