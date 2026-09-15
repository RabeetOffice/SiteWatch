<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\DomainService;

require_permission('domains.view');

$canLookup = can('domains.lookup');

$pageTitle = 'Domains & Hosting';
$pageSubtitle = 'Domain age, registration and expiry, and where every website is hosted.';
$activeNav = 'domains';
$pageScripts = ['domain-info.js', 'domains.js'];
$pageData = [
    'canLookup'   => $canLookup,
    'maxAgeHours' => max(1, (int) setting('domain_check_interval_hours', 24)),
    'warningDays' => DomainService::EXPIRY_WARNING_DAYS,
];
$headerActions = $canLookup
    ? '<button type="button" class="btn btn-light" id="btnRefreshStale"><i class="bi bi-arrow-repeat"></i>Refresh outdated</button>'
    : '';

require dirname(__DIR__) . '/includes/header.php';
?>

<h2 class="visually-hidden">Domain summary</h2>
<div class="metric-strip" id="domainMetrics">
    <button type="button" class="metric-item" data-domain-filter="all" aria-pressed="false">
        <span class="metric-label"><span class="marker brand" aria-hidden="true"></span>Domains</span>
        <span class="metric-value" data-metric="total">—</span>
        <span class="metric-sub" data-metric="checked_sub">&nbsp;</span>
    </button>
    <button type="button" class="metric-item" data-domain-filter="expiring" data-metric-item="expiring" aria-pressed="false">
        <span class="metric-label"><span class="marker warn" aria-hidden="true"></span>Expiring soon</span>
        <span class="metric-value" data-metric="expiring">—</span>
        <span class="metric-sub">within <?= (int) DomainService::EXPIRY_WARNING_DAYS ?> days</span>
    </button>
    <button type="button" class="metric-item" data-domain-filter="expired" data-metric-item="expired" aria-pressed="false">
        <span class="metric-label"><span class="marker down" aria-hidden="true"></span>Expired</span>
        <span class="metric-value" data-metric="expired">—</span>
        <span class="metric-sub">renew or check the registrar</span>
    </button>
    <button type="button" class="metric-item" data-domain-filter="failed" data-metric-item="failed" aria-pressed="false">
        <span class="metric-label">Lookup problems</span>
        <span class="metric-value" data-metric="failed">—</span>
        <span class="metric-sub">registry or DNS errors</span>
    </button>
    <div class="metric-item">
        <span class="metric-label">Average domain age</span>
        <span class="metric-value" data-metric="avg_age">—</span>
        <span class="metric-sub">across checked domains</span>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php if ($canLookup): ?>
    <div class="col-xl-7">
        <section class="sw-card h-100" aria-labelledby="lookupHeading">
            <div class="sw-card-header">
                <div><h3 id="lookupHeading">Domain lookup</h3><p class="sub">WHOIS registration, domain age and hosting for any domain. Nothing is saved.</p></div>
            </div>
            <div class="sw-card-body">
                <form class="lookup-form" id="lookupForm" novalidate>
                    <div class="search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="text" class="form-control" name="query" id="lookupQuery" maxlength="255" placeholder="example.com or https://www.example.co.uk"
                               aria-label="Domain name or website address" autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="url">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search" aria-hidden="true"></i> Look up</button>
                </form>
                <p class="form-text mb-0 mt-2">Registration comes from the domain's registry (RDAP, or WHOIS where RDAP is not offered). Hosting comes from DNS and the network that owns the server address.</p>
            </div>
        </section>
    </div>
    <?php endif; ?>
    <div class="<?= $canLookup ? 'col-xl-5' : 'col-12' ?>">
        <section class="sw-card h-100" aria-labelledby="hostingHeading">
            <div class="sw-card-header">
                <div><h3 id="hostingHeading">Where your websites are hosted</h3><p class="sub">Hosting companies and server countries of monitored websites</p></div>
            </div>
            <div class="sw-card-body" id="hostingBreakdown"><div class="skeleton skeleton-block" style="height:120px"></div></div>
        </section>
    </div>
</div>

<?php if ($canLookup): ?>
<section class="sw-card mb-4" id="lookupCard" aria-labelledby="lookupResultHeading" hidden>
    <div class="sw-card-header">
        <div><h3 id="lookupResultHeading">Lookup result</h3></div>
        <button type="button" class="btn btn-sm btn-light" id="lookupClear"><i class="bi bi-x-lg" aria-hidden="true"></i> Clear</button>
    </div>
    <div class="sw-card-body" id="lookupResult" aria-live="polite"></div>
</section>
<?php endif; ?>

<section class="sw-card" id="domainsCard" aria-labelledby="domainsHeading">
    <div class="sw-card-header">
        <div><h3 id="domainsHeading">Website domains</h3><p class="sub" id="domainsSub">Details are refreshed when older than <?= (int) $pageData['maxAgeHours'] ?> hours</p></div>
    </div>
    <div class="sw-toolbar">
        <div class="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control form-control-sm" id="domainSearch" placeholder="Search website, domain, registrar or host…" aria-label="Search domains">
        </div>
        <div class="toolbar-group">
            <select class="form-select form-select-sm" id="domainFilter" aria-label="Filter domains">
                <option value="all">All domains</option>
                <option value="expiring">Expiring within <?= (int) DomainService::EXPIRY_WARNING_DAYS ?> days</option>
                <option value="expired">Expired</option>
                <option value="failed">Lookup problems</option>
                <option value="unchecked">Not checked yet</option>
            </select>
            <select class="form-select form-select-sm" id="domainSort" aria-label="Sort domains">
                <option value="expiry">Sort: Expires soonest</option>
                <option value="age">Sort: Oldest domain</option>
                <option value="domain">Sort: Domain name</option>
                <option value="provider">Sort: Hosting provider</option>
                <option value="checked">Sort: Recently checked</option>
            </select>
        </div>
    </div>
    <div class="filter-summary" id="domainFilterSummary" hidden></div>
    <div class="sw-table-wrap">
        <table class="sw-table">
            <thead>
                <tr>
                    <th scope="col">Website</th>
                    <th scope="col" class="hide-mobile">Registrar</th>
                    <th scope="col">Domain age</th>
                    <th scope="col">Expires</th>
                    <th scope="col">Hosting</th>
                    <th scope="col" class="hide-mobile">Checked</th>
                    <th scope="col" class="actions"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody id="domainsBody"></tbody>
        </table>
    </div>
    <div class="sw-pagination" id="domainsPagination"></div>
</section>

<div class="modal fade" id="domainModal" tabindex="-1" aria-labelledby="domainModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="domainModalTitle">Domain details</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="domainModalBody"></div>
            <div class="modal-footer">
                <a class="btn btn-light me-auto" id="domainModalWebsite" href="#"><i class="bi bi-window" aria-hidden="true"></i> Website details</a>
                <?php if ($canLookup): ?><button type="button" class="btn btn-light" id="domainModalRefresh"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Refresh now</button><?php endif; ?>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
