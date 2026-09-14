<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$pageTitle = 'Import Websites';
$pageSubtitle = 'Add many client websites at once by pasting URLs or uploading a CSV file.';
$activeNav = 'website-add';
$pageScripts = ['import.js'];
$headerActions = '<a href="' . e(base_url('admin/website-add.php')) . '" class="btn btn-light"><i class="bi bi-plus-lg"></i><span class="d-none d-sm-inline">Single Website</span></a>';

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="sw-card mb-4">
            <div class="sw-card-header">
                <ul class="nav nav-tabs border-0" id="importTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPaste" type="button" role="tab"><i class="bi bi-clipboard"></i> Paste URLs</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCsv" type="button" role="tab"><i class="bi bi-filetype-csv"></i> CSV Upload</button></li>
                </ul>
            </div>
            <div class="sw-card-body">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabPaste" role="tabpanel">
                        <form id="pasteForm" novalidate>
                            <label class="form-label" for="pasteUrls">Website URLs — one per line</label>
                            <textarea class="form-control mono" id="pasteUrls" name="urls" rows="10" placeholder="example1.com&#10;example2.com | Example Two | Client Name&#10;https://example3.co.uk"></textarea>
                            <div class="form-text">Optional: append <span class="code-inline">| Website Name | Client Name</span> after a URL. Missing names are derived from the domain.</div>
                            <div class="row g-3 mt-1">
                                <div class="col-sm-4">
                                    <label class="form-label" for="pasteType">Website type</label>
                                    <select class="form-select" id="pasteType" name="type"><option value="wordpress">WordPress</option><option value="woocommerce">WooCommerce</option><option value="other">Other</option></select>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label" for="pasteInterval">Monitoring interval</label>
                                    <select class="form-select" id="pasteInterval" name="check_interval">
                                        <?php foreach ([1, 2, 5, 10, 15, 30] as $i): ?><option value="<?= $i ?>"<?= $i === (int) setting('default_check_interval', 5) ? ' selected' : '' ?>><?= $i ?> minute<?= $i === 1 ? '' : 's' ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label" for="pasteClient">Default client</label>
                                    <input type="text" class="form-control" id="pasteClient" name="client_name" placeholder="Optional">
                                </div>
                            </div>
                            <div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-primary" id="pasteSubmit"><i class="bi bi-upload"></i> Import Websites</button></div>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="tabCsv" role="tabpanel">
                        <form id="csvForm" novalidate>
                            <label class="form-label" for="csvFile">CSV file</label>
                            <input type="file" class="form-control" id="csvFile" name="file" accept=".csv,text/csv,text/plain">
                            <div class="form-text">Maximum 2 MB. Columns (header row recommended): <span class="code-inline">Website Name, Client Name, URL, Type, Check Interval</span>. Only the URL column is required.</div>
                            <div class="mt-3"><a class="btn btn-sm btn-light" href="<?= e(base_url('api/websites/import.php?template=1')) ?>"><i class="bi bi-download"></i> Download CSV template</a></div>
                            <div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-primary" id="csvSubmit"><i class="bi bi-upload"></i> Upload &amp; Import</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="sw-card d-none" id="importReport">
            <div class="sw-card-header"><div><h3>Import Report</h3><p class="sub" id="importSummary"></p></div><a class="btn btn-sm btn-light" href="<?= e(base_url('admin/websites.php')) ?>">Go to websites</a></div>
            <div class="sw-card-body import-report" id="importReportBody"></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="sw-card">
            <div class="sw-card-header"><div><h3>How import works</h3></div></div>
            <div class="sw-card-body fs-13 text-muted">
                <p>Every entry is validated and normalised (<span class="code-inline">example.com</span> → <span class="code-inline">https://example.com/</span>).</p>
                <ul class="ps-3 mb-3">
                    <li>Websites already monitored are reported as <b>duplicates</b> and skipped.</li>
                    <li>Malformed addresses are reported as <b>invalid</b>.</li>
                    <li>Targets resolving to private/internal networks are rejected for security.</li>
                    <li>Nothing is discarded silently — the full report lists every line.</li>
                </ul>
                <p class="mb-0">Imported websites use the default checks and alert rules. The first monitoring run happens within a minute of the next cron cycle.</p>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
