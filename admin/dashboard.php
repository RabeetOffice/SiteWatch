<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ServiceFactory;

$pageTitle = 'Overview';

$pageSubtitle = 'Your client websites, at a glance.';

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

<section class="overview-heading"><div><div class="eyebrow">WORKSPACE OVERVIEW</div><h2>A clear view of every website.</h2><p>Spot issues, investigate changes, and keep your clients informed.</p></div><a class="btn btn-light" href="<?= e(base_url('admin/reports.php')) ?>"><i class="bi bi-bar-chart-line"></i> View reports</a></section>

<div class="overview-metrics" id="kpiGrid">

<?php foreach ([['total','Websites','bi-globe2','primary','all','All client websites'],['online','Online','bi-check-circle','success','online','At the last check'],['down','Down','bi-exclamation-octagon','danger','down','Confirmed failures'],['warnings','Warnings','bi-exclamation-triangle','warning','warning','Slow, SSL or suspected']] as [$key,$label,$icon,$tone,$filter,$sub]): ?>

<button type="button" class="metric-card tone-<?= e($tone) ?>" data-overview-filter="<?= e($filter) ?>" aria-label="Show <?= $key === 'total' ? 'all' : e(strtolower($label)) ?> websites"><span class="metric-top"><span><?= e($label) ?></span><i class="bi <?= e($icon) ?>"></i></span><span class="metric-value" data-kpi="<?= e($key) ?>"><?= (int)$kpi[$key] ?></span><span class="metric-bottom"><span<?= $key === 'total' ? ' data-kpi="total_sub"' : '' ?>><?= $key === 'total' ? (int)$kpi['paused'] . ' paused' . (!empty($kpi['pending']) ? ', ' . (int)$kpi['pending'] . ' pending' : '') : e($sub) ?></span><i class="bi bi-arrow-up-right"></i></span></button>

<?php endforeach; ?>

</div>

<section class="sw-card attention-panel mb-4"><div class="sw-card-header"><div><h3><i class="bi bi-lightning-charge text-warning"></i>Needs attention <span class="count-chip" data-kpi="open_incidents"><?= (int)$kpi['open_incidents'] ?></span></h3><p class="sub">Confirmed incidents  -  last known status</p></div><a class="btn btn-sm btn-light" href="<?= e(base_url('admin/incidents.php?status=OPEN')) ?>">All incidents <i class="bi bi-arrow-right"></i></a></div><div id="recentIncidents"></div></section>

<div class="sw-card mb-4" id="websiteTable"></div>

<div class="section-heading"><div><div class="eyebrow">THE BIGGER PICTURE</div><h2>Performance & reliability</h2></div><span class="text-muted fs-12">Based on recorded checks</span></div>

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

            <div class="sw-card-header">

                <div><h3>Response Time Trend</h3><p class="sub">Average response across all websites · last 24 hours</p></div>

            </div>

            <div class="sw-card-body"><p class="chart-note mb-3" id="responseSampleNote"></p><div class="chart-box" style="height:200px"><canvas id="chartResponse" role="img" aria-label="Fleet average response time over the last 24 hours"></canvas></div></div>

        </div>

    </div>

</div>

<div class="row g-3 mb-4">

    <div class="col-md-6">

        <div class="sw-card h-100">

            <div class="sw-card-header"><div><h3>Incidents · Last 30 Days</h3><p class="sub">New incidents per day</p></div></div>

            <div class="sw-card-body"><div class="chart-box" style="height:200px"><canvas id="chartIncidents"></canvas></div></div>

        </div>

    </div>

    <div class="col-md-6">

        <div class="sw-card h-100">

            <div class="sw-card-header"><div><h3>Observed uptime</h3><p class="sub">Fleet-wide daily uptime · last 30 days</p></div></div>

            <div class="sw-card-body"><div class="chart-box" style="height:200px"><canvas id="chartUptime"></canvas></div></div>

        </div>

    </div>

</div>

<section class="sw-card"><div class="sw-card-header"><div><h3>Recent activity</h3><p class="sub">Monitoring and workspace updates</p></div><a href="<?= e(base_url('admin/activity.php')) ?>" class="btn btn-sm btn-light">View activity <i class="bi bi-arrow-right"></i></a></div><div id="recentActivity"></div></section>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>


