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
$pageScripts[] = 'website-tabs.js';
$pageScripts[] = 'world-dots.js';
$pageScripts[] = 'website-overview.js';
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
    'countries' => ['enabled' => App::settings()->getBool('country_checks_enabled', true), 'canRun' => $canRunChecks, 'canView' => $canViewReports],
];

// Sections of this page, shown as tabs under the website header.
$detailTabs = ['overview' => ['Overview', 'bi-grid-1x2'], 'checks' => ['Checks', 'bi-list-check'], 'wordpress' => ['WordPress', 'bi-wordpress']];
if ($canViewReports) {
    $detailTabs['performance'] = ['Performance', 'bi-speedometer2'];
}
if ($canViewDomains) {
    $detailTabs['domain'] = ['Domain & hosting', 'bi-globe-americas'];
}
$detailTabs['configuration'] = ['Configuration', 'bi-sliders'];

require dirname(__DIR__) . '/includes/header.php';
?>

<nav class="sw-breadcrumb mb-2" aria-label="Breadcrumb">
    <a href="<?= e(base_url('admin/websites.php')) ?>">Websites</a><i class="bi bi-chevron-right" aria-hidden="true"></i><span><?= e($website['name']) ?></span>
</nav>

<section class="sw-card site-header mb-0" aria-label="Website identity and status">
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
                        <?php if ($canViewDomains): ?><li><a class="dropdown-item" href="?id=<?= $id ?>&amp;tab=domain" data-tab-link="domain"><i class="bi bi-globe-americas"></i> Domain &amp; hosting</a></li><?php endif; ?>
                        <?php if (can('websites.delete')): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><button type="button" class="dropdown-item text-danger" id="btnDelete"><i class="bi bi-trash"></i> Delete website</button></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <nav class="sw-tabs site-tabs" role="tablist" aria-label="Website sections">
        <?php foreach ($detailTabs as $key => [$label, $icon]): ?>
            <button type="button" role="tab" id="tab-<?= e($key) ?>" data-tab="<?= e($key) ?>" aria-controls="pane-<?= e($key) ?>" aria-selected="<?= $key === 'overview' ? 'true' : 'false' ?>"<?= $key === 'overview' ? ' class="active"' : ' tabindex="-1"' ?>>
                <i class="bi <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span><span class="sw-tab-count" data-tab-count="<?= e($key) ?>" hidden></span>
            </button>
        <?php endforeach; ?>
    </nav>
</section>

<div class="site-pane" role="tabpanel" id="pane-overview" aria-labelledby="tab-overview" data-pane="overview">

<section class="sw-card ov-banner mb-3" id="ovBanner" aria-label="Health">
    <div class="ov-banner-main">
        <button type="button" class="ov-ring" id="ovScore" aria-expanded="false" aria-controls="ovScoreParts" title="How the health score is calculated">
            <svg viewBox="0 0 64 64" width="68" height="68" aria-hidden="true">
                <circle class="track" cx="32" cy="32" r="27"/>
                <circle class="value" cx="32" cy="32" r="27" pathLength="100" stroke-dasharray="0 100" transform="rotate(-90 32 32)"/>
            </svg>
            <span class="n" data-ov="score">–</span>
        </button>
        <div class="min-w-0 flex-grow-1">
            <div class="ov-kicker">Health score <span class="text-faint" data-ov="score_word"></span></div>
            <div class="ov-state" data-ov="state"><span class="skeleton skeleton-line" style="width:240px;display:inline-block">&nbsp;</span></div>
            <div class="ov-meta" data-ov="meta">&nbsp;</div>
        </div>
        <?php if ($canViewReports): ?><div class="ov-shot-slot" data-ov="shot"><div class="ov-shot empty"><span class="skeleton" style="position:absolute;inset:0"></span></div></div><?php endif; ?>
    </div>
    <div class="ov-score-parts" id="ovScoreParts" hidden></div>
</section>

<h2 class="visually-hidden">Key figures</h2>
<div class="ov-tiles mb-3">
    <div class="ov-tile" data-tile="uptime">
        <div class="l">Uptime · 30 days</div>
        <div class="v" data-ov="uptime">—</div>
        <div class="viz"><span class="ov-bar"><span data-ov="uptime_bar"></span></span></div>
        <div class="s" data-ov="uptime_sub">&nbsp;</div>
    </div>
    <div class="ov-tile" data-tile="response">
        <div class="l">Response · 24 hours</div>
        <div class="v" data-ov="response">—</div>
        <div class="viz" data-ov="response_spark"></div>
        <div class="s" data-ov="response_sub">&nbsp;</div>
    </div>
    <div class="ov-tile" data-tile="ssl">
        <div class="l">SSL certificate</div>
        <div class="v" data-ov="ssl">—</div>
        <div class="viz"><span class="ov-bar"><span data-ov="ssl_bar"></span></span></div>
        <div class="s" data-ov="ssl_sub">&nbsp;</div>
    </div>
    <?php if ($canViewDomains): ?>
    <a class="ov-tile" data-tile="domain" href="?id=<?= $id ?>&amp;tab=domain" data-tab-link="domain">
        <div class="l">Domain</div>
        <div class="v" data-ov="domain">—</div>
        <div class="viz"><span class="ov-bar"><span data-ov="domain_bar"></span></span></div>
        <div class="s" data-ov="domain_sub">&nbsp;</div>
    </a>
    <?php endif; ?>
</div>

<section class="sw-card mb-3" aria-labelledby="ovDaysTitle">
    <div class="sw-card-header">
        <div><h3 id="ovDaysTitle">Uptime · last 90 days</h3><p class="sub">One bar per day. Hover a day for its uptime, checks and incidents.</p></div>
        <div class="ov-days-total" data-ov="uptime_90">—</div>
    </div>
    <div class="sw-card-body">
        <div class="ov-days" id="ovDays" role="img" aria-label="Daily uptime over the last 90 days"></div>
        <div class="ov-days-labels"><span>90 days ago</span><span class="ov-days-legend"><span><i class="ok"></i>100%</span><span><i class="warn"></i>Below 99.9%</span><span><i class="down"></i>Below 95%</span><span><i class="none"></i>No data</span></span><span>Today</span></div>
    </div>
</section>

        <section class="sw-card mb-3" aria-label="Response time">
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
                <div class="chart-box" style="height:250px"><canvas id="chartRt" role="img" aria-label="Response time over the selected range"></canvas><div class="chart-empty" id="rtEmpty" hidden></div></div>
                <div class="chart-legend" id="rtLegend"></div>
            </div>
        </section>

<div class="row g-3">
    <div class="col-xl-6">
        <?php if ($canViewReports): ?>
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Countries</h3><p class="sub" data-cc-sub>Does it open from other countries?</p></div>
                <div class="d-flex gap-2">
                    <?php if ($canRunChecks): ?><button type="button" class="btn btn-sm btn-light" data-cc-run><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Check now</button><?php endif; ?>
                    <a class="btn btn-sm btn-light" href="<?= e(base_url('admin/performance.php?tab=countries')) ?>">All websites</a>
                </div>
            </div>
            <div class="sw-card-body">
                <div class="ov-map" id="ovMap" aria-hidden="true"></div>
                <div data-cc-body><div class="skeleton skeleton-block" style="height:60px"></div></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="<?= $canViewReports ? 'col-xl-6' : 'col-12' ?>">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Recent events</h3><p class="sub">Incidents, WordPress, countries and changes in SiteWatch</p></div>
                <?php if (can('incidents.view')): ?><a class="btn btn-sm btn-light" href="<?= e(base_url('admin/incidents.php?website_id=' . $id)) ?>">Incidents</a><?php endif; ?>
            </div>
            <ol class="ov-events" id="ovEvents"><li class="p-3"><div class="skeleton skeleton-line w-75">&nbsp;</div><div class="skeleton skeleton-line w-50">&nbsp;</div></li></ol>
        </div>
    </div>
</div>
</div>

<div class="site-pane" role="tabpanel" id="pane-checks" aria-labelledby="tab-checks" data-pane="checks" hidden>
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

<div class="site-pane" role="tabpanel" id="pane-wordpress" aria-labelledby="tab-wordpress" data-pane="wordpress" hidden>
<section class="sw-card" id="wordpressSection" aria-labelledby="wpTitle">
    <div class="sw-card-header">
        <div>
            <h3 id="wpTitle"><i class="bi bi-wordpress" aria-hidden="true"></i> WordPress <span class="fs-13 fw-normal text-muted">· SiteWatch Connector</span></h3>
            <p class="sub" data-wp-sub>Errors, health and changes reported from inside the site</p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap" data-wp-actions></div>
    </div>
    <div class="sw-card-body" data-wp-body><div class="skeleton skeleton-block"></div></div>
</section>
</div>

<?php if ($canViewReports): ?>
<div class="site-pane" role="tabpanel" id="pane-performance" aria-labelledby="tab-performance" data-pane="performance" hidden>
<section class="row g-3" id="performanceSection" aria-label="Core Web Vitals and screenshot">
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
</div>
<?php endif; ?>

<?php if ($canViewDomains): ?>
<div class="site-pane" role="tabpanel" id="pane-domain" aria-labelledby="tab-domain" data-pane="domain" hidden>
<section class="row g-3" id="domainSection" aria-label="Domain and hosting">
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
</div>
<?php endif; ?>

<div class="site-pane" role="tabpanel" id="pane-configuration" aria-labelledby="tab-configuration" data-pane="configuration" hidden>
<div class="row g-3">
    <div class="col-xl-8">
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
</div>

<div class="modal fade" id="shotPreview" tabindex="-1" aria-labelledby="shotPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="min-w-0">
                    <h2 class="modal-title" id="shotPreviewTitle">Screenshot of <?= e($website['name']) ?></h2>
                    <p class="fs-13 text-muted mb-0" data-shot-caption></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 shot-preview-body"><img alt="Screenshot of <?= e($website['name']) ?>" data-shot-image></div>
            <div class="modal-footer">
                <a class="btn btn-light me-auto" href="<?= e($website['url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Open the website</a>
                <a class="btn btn-light" href="#" target="_blank" rel="noopener" data-shot-full><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i>Full size</a>
                <?php if ($canRunChecks): ?><button type="button" class="btn btn-primary" data-shot-recapture><i class="bi bi-camera" aria-hidden="true"></i>Capture again</button><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
