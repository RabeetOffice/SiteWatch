<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ServiceFactory;

$pageTitle = 'Website Monitoring';
$pageSubtitle = 'Monitor the uptime, performance and health of all your client websites.';
$activeNav = 'dashboard';
$pageScripts = ['websites.js', 'dashboard.js'];
$needsCharts = true;
$headerActions = '<a href="' . e(base_url('admin/website-add.php')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline">Add Website</span></a>';

$dashboard = ServiceFactory::dashboard();
$initial = [
    'stats'     => $dashboard->stats(),
    'incidents' => $dashboard->recentIncidents(6),
    'activity'  => $dashboard->recentActivity(8),
];
$pageData = ['initial' => $initial, 'websiteCount' => ServiceFactory::websites()->count()];
$kpi = $initial['stats']['kpi'];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="kpi-grid mb-4" id="kpiGrid">
    <div class="kpi-card tone-primary"><div class="kpi-label"><i class="bi bi-globe2"></i>Total Websites</div><div class="kpi-value" data-kpi="total"><?= (int) $kpi['total'] ?></div><div class="kpi-sub" data-kpi="total_sub"><?= (int) $kpi['paused'] ?> paused</div></div>
    <div class="kpi-card tone-success"><div class="kpi-label"><i class="bi bi-check-circle"></i>Online</div><div class="kpi-value" data-kpi="online"><?= (int) $kpi['online'] ?></div><div class="kpi-sub" data-kpi="online_sub">healthy right now</div></div>
    <div class="kpi-card tone-danger<?= $kpi['down'] > 0 ? ' attention' : '' ?>" data-kpi-card="down"><div class="kpi-label"><i class="bi bi-x-octagon"></i>Down</div><div class="kpi-value" data-kpi="down"><?= (int) $kpi['down'] ?></div><div class="kpi-sub" data-kpi="down_sub">confirmed failures</div></div>
    <div class="kpi-card tone-warning<?= $kpi['warnings'] > 0 ? ' attention-warning' : '' ?>" data-kpi-card="warnings"><div class="kpi-label"><i class="bi bi-exclamation-triangle"></i>Warnings</div><div class="kpi-value" data-kpi="warnings"><?= (int) $kpi['warnings'] ?></div><div class="kpi-sub" data-kpi="warnings_sub">slow, SSL or suspected</div></div>
    <div class="kpi-card tone-info"><div class="kpi-label"><i class="bi bi-stopwatch"></i>Average Response</div><div class="kpi-value" data-kpi="avg_response"><?= e($kpi['avg_response_label']) ?></div><div class="kpi-sub">latest checks</div></div>
    <div class="kpi-card tone-danger<?= $kpi['open_incidents'] > 0 ? ' attention' : '' ?>" data-kpi-card="open_incidents"><div class="kpi-label"><i class="bi bi-exclamation-octagon"></i>Open Incidents</div><div class="kpi-value" data-kpi="open_incidents"><?= (int) $kpi['open_incidents'] ?></div><div class="kpi-sub"><a href="<?= e(base_url('admin/incidents.php')) ?>">View incidents →</a></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Health Overview</h3><p class="sub">Current state of every monitored website</p></div></div>
            <div class="sw-card-body" id="healthOverview">
                <div class="skeleton skeleton-block" style="height:140px"></div>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Response Time Trend</h3><p class="sub">Average response across all websites · last 24 hours</p></div>
            </div>
            <div class="sw-card-body"><div class="chart-box" style="height:220px"><canvas id="chartResponse"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-4 col-md-6">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Status Distribution</h3><p class="sub">Websites by health</p></div></div>
            <div class="sw-card-body"><div class="chart-box" style="height:200px"><canvas id="chartStatus"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4 col-md-6">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Incidents · Last 30 Days</h3><p class="sub">New incidents per day</p></div></div>
            <div class="sw-card-body"><div class="chart-box" style="height:200px"><canvas id="chartIncidents"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4 col-md-12">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Uptime Trend</h3><p class="sub">Fleet-wide daily uptime · last 30 days</p></div></div>
            <div class="sw-card-body"><div class="chart-box" style="height:200px"><canvas id="chartUptime"></canvas></div></div>
        </div>
    </div>
</div>

<div class="sw-card mb-4" id="websiteTable"></div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Recent Incidents</h3><p class="sub">Latest confirmed problems</p></div>
                <a href="<?= e(base_url('admin/incidents.php')) ?>" class="btn btn-sm btn-light">View all</a>
            </div>
            <div id="recentIncidents"></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Activity</h3><p class="sub">Latest system and admin events</p></div>
                <a href="<?= e(base_url('admin/activity.php')) ?>" class="btn btn-sm btn-light">View log</a>
            </div>
            <div id="recentActivity"></div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
