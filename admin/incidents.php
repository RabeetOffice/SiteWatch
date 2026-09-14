<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Monitoring\Status;
use App\Repositories\IncidentRepository;
use App\Services\ServiceFactory;

$pageTitle = 'Incidents';
$pageSubtitle = 'Confirmed outages and errors, open and resolved.';
$activeNav = 'incidents';
$pageScripts = ['incidents.js'];
$pageData = [
    'websites'  => ServiceFactory::websites()->options(),
    'clients'   => ServiceFactory::websites()->clients(),
    'types'     => array_map(static fn (string $t): array => ['key' => $t, 'label' => Status::incidentTypeLabel($t)], IncidentRepository::TYPES),
    'preset'    => ['website_id' => (int) ($_GET['website_id'] ?? 0), 'status' => strtoupper((string) ($_GET['status'] ?? ''))],
];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-card" id="incidentsPage">
    <div class="sw-card-header">
        <div><h3>Incident History</h3><p class="sub" id="incidentsSummary">Loading…</p></div>
        <div class="d-flex gap-2 align-items-center">
            <div class="segmented" id="incidentStatus">
                <button type="button" data-status="" class="active">All</button>
                <button type="button" data-status="OPEN">Open</button>
                <button type="button" data-status="RESOLVED">Resolved</button>
            </div>
            <a class="btn btn-sm btn-light" id="incidentsExport" href="<?= e(base_url('api/incidents/export.php')) ?>"><i class="bi bi-download"></i> Export CSV</a>
        </div>
    </div>
    <div class="sw-toolbar">
        <div class="search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="incidentSearch" placeholder="Search website, client, title or error…"></div>
        <select class="form-select form-select-sm" id="incidentWebsite" style="width:auto;max-width:220px"><option value="">All websites</option></select>
        <select class="form-select form-select-sm" id="incidentClient" style="width:auto;max-width:200px"><option value="">All clients</option></select>
        <select class="form-select form-select-sm" id="incidentType" style="width:auto"><option value="">All types</option></select>
        <div class="d-flex align-items-center gap-1">
            <input type="date" class="form-control form-control-sm" id="incidentFrom" style="width:auto" aria-label="From date">
            <span class="text-muted fs-13">to</span>
            <input type="date" class="form-control form-control-sm" id="incidentTo" style="width:auto" aria-label="To date">
        </div>
        <button type="button" class="btn btn-sm btn-ghost" id="incidentClear">Clear</button>
    </div>
    <div id="incidentsList"></div>
    <div class="sw-pagination" id="incidentsPagination"></div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
