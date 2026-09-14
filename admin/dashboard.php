<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ServiceFactory;

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$pageScripts = ['websites.js', 'dashboard.js'];
$needsCharts = true;
$headerActions = '<a href="' . e(base_url('admin/reports.php')) . '" class="btn btn-light"><i class="bi bi-bar-chart-line"></i>Reports</a>'
    . '<a href="' . e(base_url('admin/website-add.php')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i>Add Website</a>';

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

<h2 class="visually-hidden">Portfolio summary</h2>
<div class="metric-strip" id="kpiGrid">
    <button type="button" class="metric-item" data-kpi-item="total" data-overview-filter="all" aria-pressed="false">
        <span class="metric-label"><span class="marker brand" aria-hidden="true"></span>Websites</span>
        <span class="metric-value" data-kpi="total"><?= (int) $kpi['total'] ?></span>
        <span class="metric-sub" data-kpi="total_sub"><?= (int) $kpi['paused'] ?> paused<?= !empty($kpi['pending']) ? ', ' . (int) $kpi['pending'] . ' pending' : '' ?></span>
    </button>
    <button type="button" class="metric-item" data-kpi-item="online" data-overview-filter="online" aria-pressed="false">
        <span class="metric-label"><span class="marker ok" aria-hidden="true"></span>Online</span>
        <span class="metric-value" data-kpi="online"><?= (int) $kpi['online'] ?></span>
        <span class="metric-sub">at the last check</span>
    </button>
    <button type="button" class="metric-item<?= (int) $kpi['down'] > 0 ? ' tone-danger' : '' ?>" data-kpi-item="down" data-overview-filter="down" aria-pressed="false">
        <span class="metric-label"><span class="marker down" aria-hidden="true"></span>Down</span>
        <span class="metric-value" data-kpi="down"><?= (int) $kpi['down'] ?></span>
        <span class="metric-sub">confirmed failures</span>
    </button>
    <button type="button" class="metric-item<?= (int) $kpi['warnings'] > 0 ? ' tone-warning' : '' ?>" data-kpi-item="warnings" data-overview-filter="warning" aria-pressed="false">
        <span class="metric-label"><span class="marker warn" aria-hidden="true"></span>Warnings</span>
        <span class="metric-value" data-kpi="warnings"><?= (int) $kpi['warnings'] ?></span>
        <span class="metric-sub">slow, SSL or suspected</span>
    </button>
    <a class="metric-item<?= (int) $kpi['open_incidents'] > 0 ? ' tone-danger' : '' ?>" data-kpi-item="open_incidents" href="<?= e(base_url('admin/incidents.php?status=OPEN')) ?>">
        <span class="metric-label">Open incidents</span>
        <span class="metric-value" data-kpi="open_incidents"><?= (int) $kpi['open_incidents'] ?></span>
        <span class="metric-sub">unresolved</span>
    </a>
    <div class="metric-item" data-kpi-item="avg_response">
        <span class="metric-label">Avg response</span>
        <span class="metric-value" data-kpi="avg_response"><?= e($kpi['avg_response_label']) ?></span>
        <span class="metric-sub">last recorded check</span>
    </div>
</div>

<section class="sw-card mb-4" aria-labelledby="attentionHeading">
    <div class="sw-card-header">
        <div>
            <h3 id="attentionHeading">Needs attention</h3>
            <p class="sub">Confirmed, currently open incidents</p>
        </div>
        <a class="btn btn-sm btn-light" href="<?= e(base_url('admin/incidents.php?status=OPEN')) ?>">All incidents <i class="bi bi-arrow-right"></i></a>
    </div>
    <div id="recentIncidents"></div>
</section>

<section class="sw-card mb-4" id="websiteTable" aria-label="Website inventory"></section>

<div class="sw-section-head">
    <div>
        <h2>Trends</h2>
        <p class="sub">Based on recorded checks only — gaps are not evidence of availability</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Portfolio health</h3><p class="sub">Last known website states</p></div></div>
            <div class="sw-card-body" id="healthOverview">
                <div class="skeleton skeleton-block" style="height:140px"></div>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Response time</h3><p class="sub">Average across all websites · last 24 hours</p></div></div>
            <div class="sw-card-body">
                <p class="chart-note" id="responseSampleNote"></p>
                <div class="chart-box" style="height:196px"><canvas id="chartResponse" role="img" aria-label="Average response time across all websites over the last 24 hours"></canvas></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>New incidents</h3><p class="sub">Per day · last 30 days</p></div></div>
            <div class="sw-card-body"><div class="chart-box" style="height:196px"><canvas id="chartIncidents" role="img" aria-label="New incidents per day over the last 30 days"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Observed uptime</h3><p class="sub">Fleet-wide daily uptime · last 30 days</p></div></div>
            <div class="sw-card-body"><div class="chart-box" style="height:196px"><canvas id="chartUptime" role="img" aria-label="Fleet-wide observed daily uptime over the last 30 days"></canvas></div></div>
        </div>
    </div>
</div>

<section class="sw-card">
    <div class="sw-card-header">
        <div><h3>Recent activity</h3><p class="sub">Monitoring and workspace events</p></div>
        <a href="<?= e(base_url('admin/activity.php')) ?>" class="btn btn-sm btn-light">Activity log <i class="bi bi-arrow-right"></i></a>
    </div>
    <div id="recentActivity"></div>
</section>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
