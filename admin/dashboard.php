<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ConnectorService;
use App\Services\ServiceFactory;

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$pageScripts = ['dashboard.js'];
$needsCharts = true;
$canIncidents = can('incidents.view');
$canActivity = can('activity.view');
$headerActions = can('websites.manage') ? '<a href="' . e(base_url('admin/website-add.php')) . '" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add website</a>' : '';

$dashboard = ServiceFactory::dashboard();

$initial = [
    'stats'     => $dashboard->stats(),
    'incidents' => $canIncidents ? $dashboard->recentIncidents(6) : [],
    'activity'  => $canActivity ? $dashboard->recentActivity(8) : [],
];

$pageData = ['initial' => $initial, 'websiteCount' => ServiceFactory::websites()->count()];

$kpi = $initial['stats']['kpi'];
$countryProblems = 0;
if (can('reports.view')) {
    try {
        $countryProblems = (int) ServiceFactory::countryChecks()->pageData(false, false)['counts']['problem'];
    } catch (Throwable) {
        // Country checks are optional; never let them break the dashboard.
    }
}
$fleet = ConnectorService::create()->fleet();
$fleetTones = ['connected' => 'success', 'pending' => 'info', 'stale' => 'warning', 'deactivated' => 'warning', 'disconnected' => 'warning'];

require dirname(__DIR__) . '/includes/header.php';
?>

<h2 class="visually-hidden">Portfolio summary</h2>
<div class="metric-strip" id="kpiGrid">
    <a class="metric-item" data-kpi-item="total" href="<?= e(base_url('admin/websites.php?filter=all')) ?>">
        <span class="metric-label"><span class="marker brand" aria-hidden="true"></span>Websites</span>
        <span class="metric-value" data-kpi="total"><?= (int) $kpi['total'] ?></span>
        <span class="metric-sub" data-kpi="total_sub"><?= (int) $kpi['paused'] ?> paused<?= !empty($kpi['pending']) ? ', ' . (int) $kpi['pending'] . ' pending' : '' ?></span>
    </a>
    <a class="metric-item" data-kpi-item="online" href="<?= e(base_url('admin/websites.php?filter=online')) ?>">
        <span class="metric-label"><span class="marker ok" aria-hidden="true"></span>Online</span>
        <span class="metric-value" data-kpi="online"><?= (int) $kpi['online'] ?></span>
        <span class="metric-sub">at the last check</span>
    </a>
    <a class="metric-item<?= (int) $kpi['down'] > 0 ? ' tone-danger' : '' ?>" data-kpi-item="down" href="<?= e(base_url('admin/websites.php?filter=down')) ?>">
        <span class="metric-label"><span class="marker down" aria-hidden="true"></span>Down</span>
        <span class="metric-value" data-kpi="down"><?= (int) $kpi['down'] ?></span>
        <span class="metric-sub">confirmed failures</span>
    </a>
    <a class="metric-item<?= (int) $kpi['warnings'] > 0 ? ' tone-warning' : '' ?>" data-kpi-item="warnings" href="<?= e(base_url('admin/websites.php?filter=warning')) ?>">
        <span class="metric-label"><span class="marker warn" aria-hidden="true"></span>Warnings</span>
        <span class="metric-value" data-kpi="warnings"><?= (int) $kpi['warnings'] ?></span>
        <span class="metric-sub">slow, SSL or suspected</span>
    </a>
    <<?= $canIncidents ? 'a' : 'div' ?> class="metric-item<?= (int) $kpi['open_incidents'] > 0 ? ' tone-danger' : '' ?>" data-kpi-item="open_incidents"<?= $canIncidents ? ' href="' . e(base_url('admin/incidents.php?status=OPEN')) . '"' : '' ?>>
        <span class="metric-label">Open incidents</span>
        <span class="metric-value" data-kpi="open_incidents"><?= (int) $kpi['open_incidents'] ?></span>
        <span class="metric-sub">unresolved</span>
    </<?= $canIncidents ? 'a' : 'div' ?>>
    <div class="metric-item" data-kpi-item="avg_response">
        <span class="metric-label">Avg response</span>
        <span class="metric-value" data-kpi="avg_response"><?= e($kpi['avg_response_label']) ?></span>
        <span class="metric-sub">last recorded check</span>
    </div>
</div>

<?php if ($countryProblems > 0): ?>
<a class="sw-notice tone-warning mb-3" href="<?= e(base_url('admin/performance.php?tab=countries')) ?>">
    <i class="bi bi-globe-europe-africa" aria-hidden="true"></i>
    <span><b><?= $countryProblems ?> website<?= $countryProblems === 1 ? '' : 's' ?></b> cannot be opened from some countries.</span>
    <span class="ms-auto fw-600">View countries <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
</a>
<?php endif; ?>

<?php if ($canIncidents): ?>
<section class="sw-card mb-4" aria-labelledby="attentionHeading">
    <div class="sw-card-header">
        <div>
            <h3 id="attentionHeading">Needs attention</h3>
            <p class="sub">Confirmed, currently open incidents</p>
        </div>
        <a class="btn btn-sm btn-light" href="<?= e(base_url('admin/incidents.php?status=OPEN')) ?>">All incidents <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <div id="recentIncidents"></div>
</section>
<?php endif; ?>

<section class="sw-card mb-4" aria-labelledby="wpFleetHeading">
    <div class="sw-card-header">
        <div>
            <h3 id="wpFleetHeading"><i class="bi bi-plug-fill" aria-hidden="true"></i> WordPress plugin</h3>
            <p class="sub">
                <b><?= (int) $fleet['connected'] ?></b> of <?= (int) $fleet['total_websites'] ?> websites connected with SiteWatch Connector<?= $fleet['attention'] > 0 ? ' · <span class="text-warning">' . (int) $fleet['attention'] . ' not reporting</span>' : '' ?>
            </p>
        </div>
        <?php if ($fleet['connected'] > 0): ?><a class="btn btn-sm btn-light" href="<?= e(base_url('admin/websites.php?filter=connector')) ?>">Connected sites <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
    </div>
    <?php if ($fleet['sites'] === []): ?>
        <div class="sw-card-body fs-13 text-muted">
            No website has the plugin yet. Open a WordPress website, go to its <b>WordPress</b> section and click <b>Download plugin</b>:
            SiteWatch will then show the real cause of fatal errors, pending updates and security problems.
        </div>
    <?php else: ?>
        <div class="row g-0">
            <div class="col-lg-6">
                <ul class="wp-fleet-list" aria-label="Websites with the plugin">
                    <?php foreach (array_slice($fleet['sites'], 0, 8) as $site): ?>
                        <li>
                            <span class="sw-pill tone-<?= e($fleetTones[$site['state']] ?? 'neutral') ?>"><?= e($site['state_label']) ?></span>
                            <a class="n" href="<?= e($site['url']) ?>"><?= e($site['name']) ?></a>
                            <span class="d ms-auto">
                                <?= $site['wp_version'] ? 'WP ' . e($site['wp_version']) . ' · ' : '' ?><?= $site['updates'] ? (int) $site['updates'] . ' updates · ' : '' ?><?= $site['issues'] ? '<span class="text-danger">' . (int) $site['issues'] . ' security</span> · ' : '' ?><?= e($site['last_seen_ago']) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (count($fleet['sites']) > 8): ?><li class="d">…and <?= count($fleet['sites']) - 8 ?> more</li><?php endif; ?>
                </ul>
            </div>
            <div class="col-lg-6">
                <ul class="wp-fleet-list" aria-label="Recent WordPress errors and security events">
                    <?php if ($fleet['recent'] === []): ?>
                        <li class="d"><i class="bi bi-check2-circle text-success" aria-hidden="true"></i> No fatal errors or security events in the last 7 days.</li>
                    <?php endif; ?>
                    <?php foreach ($fleet['recent'] as $event): ?>
                        <li>
                            <i class="bi <?= $event['type'] === 'fatal_error' ? 'bi-bug-fill text-danger' : 'bi-shield-exclamation text-warning' ?>" aria-hidden="true"></i>
                            <a class="n" href="<?= e($event['url']) ?>"><?= e($event['website_name']) ?></a>
                            <span class="d" title="<?= e($event['title']) ?>"><?= e($event['title']) ?></span>
                            <span class="d ms-auto"><?= e($event['last_ago']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</section>

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

<?php if ($canActivity): ?>
<section class="sw-card">
    <div class="sw-card-header">
        <div><h3>Recent activity</h3><p class="sub">Monitoring and workspace events</p></div>
        <a href="<?= e(base_url('admin/activity.php')) ?>" class="btn btn-sm btn-light">Activity log <i class="bi bi-arrow-right"></i></a>
    </div>
    <div id="recentActivity"></div>
</section>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
