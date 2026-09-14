<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Response;
use App\Services\ServiceFactory;

App::auth()->requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$website = $id > 0 ? ServiceFactory::websites()->find($id) : null;
if ($website === null) {
    App::session()->flash('toast', ['message' => 'Website not found.', 'type' => 'danger']);
    Response::redirect(base_url('admin/websites.php'));
}

$service = ServiceFactory::websiteService();
$presented = $service->present($website);

$pageTitle = $website['name'];
$pageSubtitle = $website['domain'] . ($website['client_name'] !== '' ? ' · ' . $website['client_name'] : '');
$activeNav = 'websites';
$pageScripts = ['website-details.js'];
$needsCharts = true;
$headerActions = '<a href="' . e(base_url('admin/websites.php')) . '" class="btn btn-light d-none d-md-inline-flex"><i class="bi bi-arrow-left"></i>All websites</a>';
$pageData = ['id' => $id, 'website' => $presented];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-card mb-4">
    <div class="sw-card-body">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div id="siteFavicon"><?php if ($website['favicon_url']): ?><img class="sw-favicon lg" src="<?= e($website['favicon_url']) ?>" alt="" width="48" height="48"><?php else: ?><span class="sw-favicon-fallback lg"><i class="bi bi-globe2"></i></span><?php endif; ?></div>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h2 class="mb-0" style="font-size:22px"><?= e($website['name']) ?></h2>
                    <span id="siteStatus"><?= '<span class="sw-badge lg sev-' . e($presented['severity']) . '">' . e($presented['status_label']) . '</span>' ?></span>
                </div>
                <div class="text-muted mt-1 d-flex flex-wrap gap-3 fs-13">
                    <a href="<?= e($website['url']) ?>" target="_blank" rel="noopener noreferrer" class="text-muted"><i class="bi bi-link-45deg"></i> <?= e($website['url']) ?></a>
                    <?php if ($website['client_name'] !== ''): ?><span><i class="bi bi-briefcase"></i> <?= e($website['client_name']) ?></span><?php endif; ?>
                    <span><i class="bi bi-wordpress"></i> <?= e($presented['type_label']) ?></span>
                    <span><i class="bi bi-clock"></i> every <?= (int) $website['check_interval'] ?> min</span>
                    <span id="siteLastChecked"><i class="bi bi-arrow-repeat"></i> Last checked <?= $website['last_checked_at'] ? '<span data-timeago="' . e($website['last_checked_at']) . '">' . e(time_ago($website['last_checked_at'])) . '</span>' : 'never' ?></span>
                </div>
                <div class="fs-13 mt-1 text-muted" id="siteError"><?= $presented['last_error_message'] && $presented['severity'] !== 'ok' ? '<i class="bi bi-info-circle"></i> ' . e($presented['last_error_message']) : '' ?></div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-primary" id="btnCheckNow"><i class="bi bi-arrow-repeat"></i> Check Now</button>
                <a href="<?= e(base_url('admin/website-edit.php?id=' . $id)) ?>" class="btn btn-light"><i class="bi bi-pencil"></i> Edit</a>
                <button type="button" class="btn btn-light" id="btnPause" data-enabled="<?= $presented['monitoring_enabled'] ? '1' : '0' ?>"><i class="bi <?= $presented['monitoring_enabled'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i> <?= $presented['monitoring_enabled'] ? 'Pause Monitoring' : 'Resume Monitoring' ?></button>
                <a href="<?= e($website['url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-light"><i class="bi bi-box-arrow-up-right"></i> Open Website</a>
                <div class="dropdown">
                    <button type="button" class="btn btn-light" data-bs-toggle="dropdown" aria-label="More"><i class="bi bi-three-dots"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= e(base_url('admin/incidents.php?website_id=' . $id)) ?>"><i class="bi bi-exclamation-octagon"></i> Incident history</a></li>
                        <li><a class="dropdown-item" href="<?= e(base_url('admin/reports.php?website_id=' . $id)) ?>"><i class="bi bi-bar-chart-line"></i> Uptime report</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><button type="button" class="dropdown-item text-danger" id="btnDelete"><i class="bi bi-trash"></i> Delete website</button></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="stat-grid mb-4" id="statGrid">
    <div class="stat-tile"><div class="l">Current Status</div><div class="v" data-stat="status"><?= e($presented['status_label']) ?></div><div class="s" data-stat="status_sub"><?= $presented['last_http_status'] ? 'HTTP ' . (int) $presented['last_http_status'] : '&nbsp;' ?></div></div>
    <div class="stat-tile"><div class="l">24h Uptime</div><div class="v" data-stat="uptime_24h"><div class="skeleton skeleton-line w-50">&nbsp;</div></div><div class="s" data-stat="uptime_24h_sub">&nbsp;</div></div>
    <div class="stat-tile"><div class="l">7d Uptime</div><div class="v" data-stat="uptime_7d"><div class="skeleton skeleton-line w-50">&nbsp;</div></div><div class="s">&nbsp;</div></div>
    <div class="stat-tile"><div class="l">30d Uptime</div><div class="v" data-stat="uptime_30d"><div class="skeleton skeleton-line w-50">&nbsp;</div></div><div class="s" data-stat="uptime_90d_sub">&nbsp;</div></div>
    <div class="stat-tile"><div class="l">Average Response</div><div class="v" data-stat="avg_24h"><div class="skeleton skeleton-line w-50">&nbsp;</div></div><div class="s" data-stat="avg_sub">last 24 hours</div></div>
    <div class="stat-tile"><div class="l">Incidents This Month</div><div class="v" data-stat="incidents_month"><div class="skeleton skeleton-line w-25">&nbsp;</div></div><div class="s" data-stat="incidents_sub">&nbsp;</div></div>
    <div class="stat-tile"><div class="l">SSL Status</div><div class="v" data-stat="ssl"><?= e($presented['ssl']['label']) ?></div><div class="s" data-stat="ssl_sub"><?= e($presented['ssl']['applicable'] && $presented['ssl']['expires_at'] ? 'expires ' . $presented['ssl']['expires_label'] : ($presented['ssl']['error'] ?: '')) ?>&nbsp;</div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Response Time</h3><p class="sub" id="rtSummary">Loading…</p></div>
                <div class="segmented" id="rtRange">
                    <button type="button" data-range="24h" class="active">24 Hours</button>
                    <button type="button" data-range="7d">7 Days</button>
                    <button type="button" data-range="30d">30 Days</button>
                    <button type="button" data-range="90d">90 Days</button>
                </div>
            </div>
            <div class="sw-card-body"><div class="chart-box" style="height:260px"><canvas id="chartRt"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Uptime Timeline</h3><p class="sub">Recent checks and the last 30 days</p></div></div>
            <div class="sw-card-body">
                <div class="fs-12 text-muted fw-600 text-uppercase mb-2" style="letter-spacing:.04em">Latest checks</div>
                <div class="timeline-bar" id="timelineChecks"><div class="skeleton w-100">&nbsp;</div></div>
                <div class="timeline-labels" id="timelineChecksLabels"></div>
                <div class="fs-12 text-muted fw-600 text-uppercase mt-4 mb-2" style="letter-spacing:.04em">Last 30 days</div>
                <div class="timeline-bar days" id="timelineDays"><div class="skeleton w-100">&nbsp;</div></div>
                <div class="timeline-labels" id="timelineDaysLabels"></div>
                <div class="d-flex gap-3 mt-3 fs-12 text-muted flex-wrap">
                    <span><span class="dot dot-ok d-inline-block me-1" style="width:9px;height:9px;border-radius:3px"></span>Online</span>
                    <span><span class="dot dot-warn d-inline-block me-1" style="width:9px;height:9px;border-radius:3px"></span>Warning / slow</span>
                    <span><span class="dot dot-down d-inline-block me-1" style="width:9px;height:9px;border-radius:3px"></span>Down / critical</span>
                    <span><span class="dot dot-paused d-inline-block me-1" style="width:9px;height:9px;border-radius:3px"></span>No data</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="sw-card">
            <div class="sw-card-header">
                <div><h3>Recent Checks</h3><p class="sub">Individual monitoring results</p></div>
                <div class="segmented" id="checksFilter"><button type="button" data-only="" class="active">All</button><button type="button" data-only="failures">Failures</button></div>
            </div>
            <div class="sw-table-wrap">
                <table class="sw-table compact">
                    <thead><tr><th>Time</th><th>Status</th><th>HTTP</th><th>Response</th><th>Error</th><th class="hide-mobile">Source</th></tr></thead>
                    <tbody id="checksBody"><?= '' ?></tbody>
                </table>
            </div>
            <div class="sw-pagination" id="checksPagination"></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="sw-card mb-3">
            <div class="sw-card-header"><div><h3>Incidents</h3><p class="sub">Latest for this website</p></div><a class="btn btn-sm btn-light" href="<?= e(base_url('admin/incidents.php?website_id=' . $id)) ?>">All</a></div>
            <div id="siteIncidents"></div>
        </div>
        <div class="sw-card">
            <div class="sw-card-header"><div><h3>Configuration</h3></div></div>
            <div class="sw-card-body">
                <dl class="kv-list mb-0" id="siteConfig">
                    <dt>Type</dt><dd><?= e($presented['type_label']) ?></dd>
                    <dt>Interval</dt><dd>Every <?= (int) $website['check_interval'] ?> minute<?= (int) $website['check_interval'] === 1 ? '' : 's' ?></dd>
                    <dt>Confirmation</dt><dd><?= (int) $presented['failure_threshold'] ?> failures → incident · <?= (int) $presented['recovery_threshold'] ?> successes → recovery</dd>
                    <dt>Checks</dt><dd><?php $enabled = array_keys(array_filter($presented['checks'])); echo e(implode(', ', array_map(static fn ($k) => \App\Services\WebsiteService::CHECK_LABELS[$k] ?? $k, $enabled))); ?></dd>
                    <dt>Added</dt><dd><?= e(format_datetime($website['created_at'])) ?></dd>
                    <?php if ($presented['ssl']['applicable'] && $presented['ssl']['issuer']): ?><dt>SSL issuer</dt><dd><?= e($presented['ssl']['issuer']) ?></dd><?php endif; ?>
                    <?php if ($website['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($website['notes'])) ?></dd><?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
