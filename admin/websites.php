<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$pageTitle = 'Websites';
$pageSubtitle = 'Every client website under monitoring, with live status.';
$activeNav = 'websites';
$pageScripts = ['websites.js'];
$headerActions = '<a href="' . e(base_url('admin/website-import.php')) . '" class="btn btn-light d-none d-md-inline-flex"><i class="bi bi-upload"></i>Import</a>'
    . '<a href="' . e(base_url('admin/website-add.php')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline">Add Website</span></a>';

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-card" id="websitesPage"></div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
