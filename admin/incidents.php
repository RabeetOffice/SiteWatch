<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_permission('incidents.view');

use App\Monitoring\Status;
use App\Repositories\IncidentRepository;
use App\Services\ServiceFactory;

$pageTitle = 'Incidents';
$activeNav = 'incidents';
$pageScripts = ['incidents.js'];
$headerActions = '<a class="btn btn-light" id="incidentsExport" href="' . e(base_url('api/incidents/export.php')) . '"><i class="bi bi-download"></i>Export CSV</a>';
$pageData = [
    'websites'  => ServiceFactory::websites()->options(),
    'clients'   => ServiceFactory::websites()->clients(),
    'types'     => array_map(static fn (string $t): array => ['key' => $t, 'label' => Status::incidentTypeLabel($t)], IncidentRepository::TYPES),
    'preset'    => ['website_id' => (int) ($_GET['website_id'] ?? 0), 'status' => strtoupper((string) ($_GET['status'] ?? ''))],
];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-card" id="incidentsPage">
    <div class="sw-toolbar">
        <div class="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control form-control-sm" id="incidentSearch" aria-label="Search incidents" placeholder="Search website, client, title or error…">
        </div>
        <div class="segmented" id="incidentStatus" role="group" aria-label="Filter by state">
            <button type="button" data-status="" class="active">All</button>
            <button type="button" data-status="OPEN">Open</button>
            <button type="button" data-status="RESOLVED">Resolved</button>
        </div>
        <div class="toolbar-group">
            <select class="form-select form-select-sm" id="incidentWebsite" aria-label="Filter by website"><option value="">All websites</option></select>
            <select class="form-select form-select-sm" id="incidentClient" aria-label="Filter by client"><option value="">All clients</option></select>
            <select class="form-select form-select-sm" id="incidentType" aria-label="Filter by incident type"><option value="">All types</option></select>
        </div>
        <div class="date-range">
            <label class="inline-label" for="incidentFrom">From</label>
            <input type="date" class="form-control form-control-sm" id="incidentFrom" aria-label="From date">
            <label class="inline-label" for="incidentTo">to</label>
            <input type="date" class="form-control form-control-sm" id="incidentTo" aria-label="To date">
        </div>
        <button type="button" class="btn btn-sm btn-ghost" id="incidentClear">Clear filters</button>
        <div class="spacer"></div>
        <span class="fs-13 text-muted" id="incidentsSummary">Loading…</span>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table">
            <thead>
                <tr>
                    <th scope="col">Website</th>
                    <th scope="col">Issue</th>
                    <th scope="col">State</th>
                    <th scope="col">Started</th>
                    <th scope="col" class="num">Duration</th>
                    <th scope="col" class="hide-mobile">Alert</th>
                </tr>
            </thead>
            <tbody id="incidentsList"></tbody>
        </table>
    </div>
    <div class="sw-pagination" id="incidentsPagination"></div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
