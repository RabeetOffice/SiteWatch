<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ActivityService;

require_permission('activity.view');

$pageTitle = 'Activity Log';
$activeNav = 'activity';
$pageScripts = ['activity.js'];
$pageData = ['actions' => ActivityService::ACTION_LABELS];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-card" id="activityPage">
    <div class="sw-toolbar">
        <div class="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control form-control-sm" id="activitySearch" aria-label="Search activity" placeholder="Search description or website…">
        </div>
        <select class="form-select form-select-sm" id="activityAction" aria-label="Filter by event type"><option value="">All event types</option></select>
        <div class="spacer"></div>
        <span class="fs-13 text-muted" id="activitySummary"></span>
        <button type="button" class="btn-icon btn-sm" id="activityRefresh" aria-label="Refresh activity log" data-bs-toggle="tooltip" title="Refresh"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
    </div>
    <div class="event-head" aria-hidden="true">
        <span>Event</span>
        <span>Website</span>
        <span>Performed by</span>
        <span>When</span>
    </div>
    <div id="activityList"></div>
    <div class="sw-pagination" id="activityPagination"></div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
