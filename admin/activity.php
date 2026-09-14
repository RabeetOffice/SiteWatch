<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ActivityService;

$pageTitle = 'Activity Log';
$pageSubtitle = 'Meaningful administrative and monitoring events.';
$activeNav = 'activity';
$pageScripts = ['activity.js'];
$pageData = ['actions' => ActivityService::ACTION_LABELS];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-card" id="activityPage">
    <div class="sw-toolbar">
        <div class="search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="activitySearch" placeholder="Search description or website…"></div>
        <select class="form-select form-select-sm" id="activityAction" style="width:auto"><option value="">All events</option></select>
        <div class="spacer"></div>
        <button type="button" class="btn-icon btn-sm" id="activityRefresh" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
    <div id="activityList"></div>
    <div class="sw-pagination" id="activityPagination"></div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
