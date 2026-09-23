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
$activeNav = 'websites';
$canViewDomains = can('domains.view');
$canViewReports = can('reports.view');
$canRunChecks = can('websites.check');
$performance = App::settings();

$pageScripts = ['website-details.js'];
if ($canViewDomains) {
    array_unshift($pageScripts, 'domain-info.js');
}
if ($canViewReports) {
    $pageScripts[] = 'website-performance.js';
}
$pageScripts[] = 'website-connector.js';
$needsCharts = true;
$hidePageHead = true;
$pageData = [
    'id'      => $id,
    'website' => $presented,
    'performance' => [
        'vitalsEnabled'      => $performance->getBool('vitals_enabled'),
        'screenshotsEnabled' => $performance->getBool('screenshot_enabled'),
        'canRun'             => $canRunChecks,
        'canManageSettings'  => can('settings.manage'),
    ],
    'connector' => ['canManage' => can('websites.manage'), 'canRemote' => can('websites.remote')],
];

require dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="Breadcrumb" class="mb-3">
    <a class="fs-13" href="<?= e(base_url('admin/websites.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> All websites</a>
</nav>

<section class="sw-card mb-4" aria-label="Website identity and status">
    <div class="sw-card-body">
        <div class="site-identity">
            <div id="siteFavicon" aria-hidden="true">
                <?php if ($website['favicon_url']): ?>
                    <img class="sw-favicon lg" src="<?= e($website['favicon_url']) ?>" alt="" width="40" height="40">
                <?php else: ?>
                    <span class="sw-favicon-fallback lg"><i class="bi bi-globe2"></i></span>
                <?php endif; ?>
            </div>
            <div class="site-identity-main">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1><?= e($website['name']) ?></h1>
                    <span id="siteStatus"><span class="sw-badge lg sev-<?= e($presented['severity']) ?>"><?= e($presented['status_label']) ?></span></span>
                    <?php if (!$presented['monitoring_enabled']): ?>
                        <span class="sw-pill tone-neutral"><i class="bi bi-pause-circle" aria-hidden="true"></i>Monitoring paused</span>
                    <?php endif; ?>
                </div>
                <div class="site-identity-meta">
                    <a href="<?= e($website['url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-link-45deg" aria-hidden="true"></i> <?= e($website['url']) ?></a>
                    <?php if ($website['client_name'] !== ''): ?><span><i class="bi bi-briefcase" aria-hidden="true"></i> <?= e($website['client_name']) ?></span><?php endif; ?>
                    <span><i class="bi bi-wordpress" aria-hidden="true"></i> <?= e($presented['type_label']) ?></span>
                    <span><i class="bi bi-clock" aria-hidden="true"></i> Checked every <?= (int) $website['check_interval'] ?> min</span>
                    <span id="siteLastChecked"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Last checked <?= $website['last_checked_at'] ? '<span data-timeago="' . e($website['last_checked_at']) . '">' . e(time_ago($website['last_checked_at'])) . '</span>' : 'never' ?></span>
                </div>
                <div class="site-identity-issue" id="siteError"><?= $presented['last_error_message'] && $presented['severity'] !== 'ok' ? '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' . e($presented['last_error_message']) : '' ?></div>
            </div>
            <div class="site-identity-actions">
                <?php if (can('websites.check')): ?>
                    <button type="button" class="btn btn-primary" id="btnCheckNow"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Check now</button>
                <?php endif; ?>
                <?php if (can('websites.manage')): ?>
                    <a href="<?= e(base_url('admin/website-edit.php?id=' . $id)) ?>" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
                    <button type="button" class="btn btn-light" id="btnPause" data-enabled="<?= $presented['monitoring_enabled'] ? '1' : '0' ?>">
                        <i class="bi <?= $presented['monitoring_enabled'] ? 'bi-pause-circle' : 'bi-play-circle' ?>" aria-hidden="true"></i> <?= $presented['monitoring_enabled'] ? 'Pause' : 'Resume' ?>
                    </button>
                <?php endif; ?>
                <div class="dropdown">
                    <button type="button" class="btn btn-light" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= e($website['url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Open website</a></li>
                        <?php if (can('incidents.view')): ?><li><a class="dropdown-item" href="<?= e(base_url('admin/incidents.php?website_id=' . $id)) ?>"><i class="bi bi-exclamation-octagon"></i> Incident history</a></li><?php endif; ?>
                        <?php if (can('reports.view')): ?><li><a class="dropdown-item" href="<?= e(base_url('admin/reports.php?website_id=' . $id)) ?>"><i class="bi bi-bar-chart-line"></i> Uptime report</a></li><?php endif; ?>
                        <?php if ($canViewDomains): ?><li><a class="dropdown-item" href="#domainSection"><i class="bi bi-globe-americas"></i> Domain &amp; hosting</a></li><?php endif; ?>
                        <?php if (can('websites.delete')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><button type="button" class="dropdown-item text-danger" id="btnDelete"><i class="bi bi-trash"></i> Delete website</button></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<h2 class="visually-hidden">Key figures</h2>
<div class="stat-groups mb-4" id="statGrid">
    <div class="stat-group">
        <h3>Availability</h3>
        <div class="stat-rows">
            <div class="stat-row"><span class="l">24 hours</span><span class="v lead" data-stat="uptime_24h"><span class="skeleton skeleton-line" style="width:64px;display:inline-block">&nbsp;</span></span></div>
            <div class="stat-row"><span class="l">7 days</span><span class="v" data-stat="uptime_7d"><span class="skeleton skeleton-line" style="width:64px;display:inline-block">&nbsp;</span></span></div>
            <div class="stat-row"><span class="l">30 days</span><span class="v" data-stat="uptime_30d"><span class="skeleton skeleton-line" style="width:64px;display:inline-block">&nbsp;</span></span></div>
        </div>
        <p class="stat-note"><span data-stat="uptime_24h_sub">&nbsp;</span> in the last 24 hours · <span data-stat="uptime_90d_sub">&nbsp;</span></p>
    </div>
    <div class="stat-group">
        <h3>Response time</h3>
        <div class="stat-rows">
            <div class="stat-row"><span class="l">Average · 24 hours</span><span class="v lead" data-stat="avg_24h"><span class="skeleton skeleton-line" style="width:64px;display:inline-block">&nbsp;</span></span></div>
            <div class="stat-row"><span class="l">Latest check</span><span class="v" data-stat="status_sub"><?= $presented['last_http_status'] ? 'HTTP ' . (int) $presented['last_http_status'] : '—' ?></span></div>
        </div>
        <p class="stat-note" data-stat="avg_sub">last 24 hours</p>
    </div>
    <div class="stat-group">
        <h3>Incidents</h3>
        <div class="stat-rows">
            <div class="stat-row"><span class="l">This month</span><span class="v lead" data-stat="incidents_month"><span class="skeleton skeleton-line" style="width:32px;display:inline-block">&nbsp;</span></span></div>
            <div class="stat-row"><span class="l">Current status</span><span class="v" data-stat="status"><?= e($presented['status_label']) ?></span></div>
        </div>
        <p class="stat-note" data-stat="incidents_sub">&nbsp;</p>
    </div>
    <div class="stat-group">
        <h3>Certificate</h3>
        <div class="stat-rows">
            <div class="stat-row"><span class="l">SSL</span><span class="v lead" data-stat="ssl"><?= e($presented['ssl']['label']) ?></span></div>
            <?php if ($presented['ssl']['applicable'] && $presented['ssl']['issuer']): ?>
                <div class="stat-row"><span class="l">Issuer</span><span class="v fs-13"><?= e($presented['ssl']['issuer']) ?></span></div>
            <?php endif; ?>
        </div>
        <p class="stat-note" data-stat="ssl_sub"><?= e($presented['ssl']['applicable'] && $presented['ssl']['expires_at'] ? 'Expires ' . $presented['ssl']['expires_label'] : ($presented['ssl']['error'] ?: '')) ?>&nbsp;</p>
    </div>
    <?php if ($canViewDomains): ?>
    <div class="stat-group">
        <h3>Domain</h3>
        <div class="stat-rows">
            <div class="stat-row"><span class="l">Age</span><span class="v lead" data-domain-stat="age"><span class="skeleton skeleton-line" style="width:72px;display:inline-block">&nbsp;</span></span></div>
            <div class="stat-row"><span class="l">Expiry</span><span class="v fs-13" data-domain-stat="expiry">—</span></div>
            <div class="stat-row"><span class="l">Hosting</span><span class="v fs-13" data-domain-stat="host">—</span></div>
        </div>
        <p class="stat-note"><a href="#domainSection">Registration and hosting details</a></p>
    </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Response time</h3><p class="sub" id="rtSummary">Loading…</p></div>
                <div class="segmented" id="rtRange" role="group" aria-label="Response time range">
                    <button type="button" data-range="24h" class="active">24 hours</button>
                    <button type="button" data-range="7d">7 days</button>
                    <button type="button" data-range="30d">30 days</button>
                    <button type="button" data-range="90d">90 days</button>
                </div>
            </div>
            <div class="sw-card-body">
                <div class="chart-box" style="height:250px"><canvas id="chartRt" role="img" aria-label="Response time over the selected range"></canvas></div>
                <div class="chart-legend" id="rtLegend"></div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Uptime timeline</h3><p class="sub">Recorded checks only</p></div></div>
            <div class="sw-card-body">
                <div class="fs-12 text-muted fw-600 mb-2">Latest checks</div>
                <div class="timeline-bar" id="timelineChecks"><div class="skeleton w-100">&nbsp;</div></div>
                <div class="timeline-labels" id="timelineChecksLabels"></div>
                <div class="fs-12 text-muted fw-600 mt-4 mb-2">Last 30 days</div>
                <div class="timeline-bar days" id="timelineDays"><div class="skeleton w-100">&nbsp;</div></div>
                <div class="timeline-labels" id="timelineDaysLabels"></div>
                <div class="chart-legend">
                    <span class="item"><span class="legend-dot dot-ok"></span>Online</span>
                    <span class="item"><span class="legend-dot dot-warn"></span>Warning or slow</span>
                    <span class="item"><span class="legend-dot dot-down"></span>Failed check</span>
                    <span class="item"><span class="legend-dot dot-paused"></span>No check recorded</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="sw-card">
            <div class="sw-card-header">
                <div><h3>Recent checks</h3><p class="sub">Individual monitoring results</p></div>
                <div class="segmented" id="checksFilter" role="group" aria-label="Filter checks">
                    <button type="button" data-only="" class="active">All</button>
                    <button type="button" data-only="failures">Failures only</button>
                </div>
            </div>
            <div class="sw-table-wrap">
                <table class="sw-table compact">
                    <thead><tr><th scope="col">Time</th><th scope="col">Result</th><th scope="col" class="num">HTTP</th><th scope="col" class="num">Response</th><th scope="col">Detail</th><th scope="col" class="hide-mobile">Source</th></tr></thead>
                    <tbody id="checksBody"></tbody>
                </table>
            </div>
            <div class="sw-pagination" id="checksPagination"></div>
        </div>
    </div>
    <div class="col-xl-4">
        <?php if (can('incidents.view')): ?>
        <div class="sw-card mb-3">
            <div class="sw-card-header">
                <div><h3>Incidents</h3><p class="sub">Latest for this website</p></div>
                <a class="btn btn-sm btn-light" href="<?= e(base_url('admin/incidents.php?website_id=' . $id)) ?>">All</a>
            </div>
            <div id="siteIncidents"></div>
        </div>
        <?php endif; ?>
        <div class="sw-card">
            <div class="sw-card-header">
                <div><h3>Configuration</h3></div>
                <?php if (can('websites.manage')): ?><a class="btn btn-sm btn-light" href="<?= e(base_url('admin/website-edit.php?id=' . $id)) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
            </div>
            <div class="sw-card-body">
                <dl class="kv-list mb-0" id="siteConfig">
                    <dt>Type</dt><dd><?= e($presented['type_label']) ?></dd>
                    <dt>Interval</dt><dd>Every <?= (int) $website['check_interval'] ?> minute<?= (int) $website['check_interval'] === 1 ? '' : 's' ?></dd>
                    <dt>Confirmation</dt><dd><?= (int) $presented['failure_threshold'] ?> failed checks open an incident · <?= (int) $presented['recovery_threshold'] ?> good checks resolve it</dd>
                    <dt>Checks</dt><dd><?php $enabled = array_keys(array_filter($presented['checks'])); echo e(implode(', ', array_map(static fn ($k) => \App\Services\WebsiteService::CHECK_LABELS[$k] ?? $k, $enabled))); ?></dd>
                    <dt>Added</dt><dd><?= e(format_datetime($website['created_at'])) ?></dd>
                    <?php if ($website['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($website['notes'])) ?></dd><?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<section class="sw-card mb-4" id="wordpressSection" aria-labelledby="wpTitle" style="scroll-margin-top:72px">
    <div class="sw-card-header">
        <div>
            <h3 id="wpTitle"><i class="bi bi-wordpress" aria-hidden="true"></i> WordPress <span class="fs-13 fw-normal text-muted">· SiteWatch Connector</span></h3>
            <p class="sub" data-wp-sub>Errors, health and changes reported from inside the site</p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap" data-wp-actions></div>
    </div>
    <div class="sw-card-body" data-wp-body><div class="skeleton skeleton-block"></div></div>
</section>

<?php if ($canViewReports): ?>
<section class="row g-3 mb-4" id="performanceSection" aria-label="Core Web Vitals and screenshot" style="scroll-margin-top:72px">
    <div class="col-xl-7">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div>
                    <h3>Core Web Vitals</h3>
                    <p class="sub" data-cwv-sub>Measured by Google PageSpeed Insights</p>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <div class="segmented" data-cwv-strategy role="group" aria-label="Device">
                        <button type="button" data-strategy="mobile" class="active">Mobile</button>
                        <button type="button" data-strategy="desktop">Desktop</button>
                    </div>
                    <?php if ($canRunChecks): ?><button type="button" class="btn btn-sm btn-light" data-cwv-run><i class="bi bi-lightning-charge" aria-hidden="true"></i> Measure now</button><?php endif; ?>
                </div>
            </div>
            <div class="sw-card-body" data-cwv-body><div class="skeleton skeleton-block"></div></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Screenshot</h3><p class="sub" data-shot-sub>How this website looks right now</p></div>
                <?php if ($canRunChecks): ?><button type="button" class="btn btn-sm btn-light" data-shot-capture><i class="bi bi-camera" aria-hidden="true"></i> Capture now</button><?php endif; ?>
            </div>
            <div class="sw-card-body" data-shot-body><div class="skeleton skeleton-block"></div></div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($canViewDomains): ?>
<section class="row g-3 mb-4" id="domainSection" aria-label="Domain and hosting" style="scroll-margin-top:72px">
    <div class="col-xl-6">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Domain registration</h3><p class="sub">WHOIS / RDAP record for <?= e(\App\Domains\DomainName::registrable((string) $website['domain'])) ?></p></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-light" data-domain-raw hidden><i class="bi bi-file-earmark-code" aria-hidden="true"></i> Raw record</button>
                    <?php if (can('domains.lookup')): ?><button type="button" class="btn btn-sm btn-light" data-domain-refresh><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Refresh</button><?php endif; ?>
                </div>
            </div>
            <div class="sw-card-body" data-domain-registration><div class="skeleton skeleton-block"></div></div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Hosting</h3><p class="sub" data-domain-checked>Where this website is served from</p></div>
            </div>
            <div class="sw-card-body" data-domain-hosting><div class="skeleton skeleton-block"></div></div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
