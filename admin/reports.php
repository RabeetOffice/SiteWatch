<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ServiceFactory;

$pageTitle = 'Uptime Reports';
$activeNav = 'reports';
$pageScripts = ['reports.js'];
$pageData = [
    'mode'     => 'uptime',
    'websites' => ServiceFactory::websites()->options(),
    'clients'  => ServiceFactory::websites()->clients(),
    'preset'   => ['website_id' => (int) ($_GET['website_id'] ?? 0)],
];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/report-layout.php';
require dirname(__DIR__) . '/includes/footer.php';
