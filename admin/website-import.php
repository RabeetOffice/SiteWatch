<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_permission('websites.manage');

$pageTitle = 'Import Websites';
$pageSubtitle = 'Add many client websites at once.';
$activeNav = 'website-add';
$pageScripts = ['import.js'];
$headerActions = '<a href="' . e(base_url('admin/website-add.php')) . '" class="btn btn-light"><i class="bi bi-plus-lg"></i>Add a single website</a>';

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-form-col">
    <div class="sw-card mb-4">
        <div class="sw-card-header p-0">
            <ul class="nav nav-tabs border-0 w-100 px-2 pt-2" id="importTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPaste" type="button" role="tab" aria-controls="tabPaste" aria-selected="true" id="tabPasteBtn"><i class="bi bi-clipboard" aria-hidden="true"></i> Paste URLs</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCsv" type="button" role="tab" aria-controls="tabCsv" aria-selected="false" id="tabCsvBtn"><i class="bi bi-filetype-csv" aria-hidden="true"></i> CSV upload</button>
                </li>
            </ul>
        </div>
        <div class="sw-card-body">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tabPaste" role="tabpanel" aria-labelledby="tabPasteBtn">
                    <form id="pasteForm" novalidate>
                        <div class="import-split">
                            <div class="min-w-0">
                                <label class="form-label" for="pasteUrls">Website URLs — one per line</label>
                                <textarea class="form-control mono" id="pasteUrls" name="urls" rows="10" aria-describedby="pasteFormat" placeholder="example1.com"></textarea>
                                <div class="form-text">Missing names are derived from the domain.</div>
                            </div>
                            <aside class="import-aside" id="pasteFormat">
                                <h3>Accepted formats</h3>
                                <pre class="copy-box mb-2">example1.com
https://example2.co.uk
example3.com | Example Three
example4.com | Example Four | Acme Ltd</pre>
                                <p class="fs-12 text-muted mb-0">Optional fields after the URL, separated by <span class="code-inline">|</span>: website name, then client name.</p>
                            </aside>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-sm-4">
                                <label class="form-label" for="pasteType">Website type</label>
                                <select class="form-select" id="pasteType" name="type"><option value="wordpress">WordPress</option><option value="woocommerce">WooCommerce</option><option value="other">Other</option></select>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label" for="pasteInterval">Check interval</label>
                                <select class="form-select" id="pasteInterval" name="check_interval">
                                    <?php foreach ([1, 2, 5, 10, 15, 30] as $i): ?><option value="<?= $i ?>"<?= $i === (int) setting('default_check_interval', 5) ? ' selected' : '' ?>><?= $i ?> minute<?= $i === 1 ? '' : 's' ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label" for="pasteClient">Default client</label>
                                <input type="text" class="form-control" id="pasteClient" name="client_name" placeholder="Optional">
                                <div class="form-text">Used when a line has no client.</div>
                            </div>
                        </div>
                        <div class="sw-form-actions mt-4">
                            <div class="me-auto"></div>
                            <button type="submit" class="btn btn-primary" id="pasteSubmit"><i class="bi bi-upload" aria-hidden="true"></i> Import websites</button>
                        </div>
                    </form>
                </div>

                <div class="tab-pane fade" id="tabCsv" role="tabpanel" aria-labelledby="tabCsvBtn">
                    <form id="csvForm" novalidate>
                        <div class="import-split">
                            <div class="min-w-0">
                                <label class="form-label" for="csvFile">CSV file</label>
                                <input type="file" class="form-control" id="csvFile" name="file" accept=".csv,text/csv,text/plain" aria-describedby="csvFormat">
                                <div class="form-text">Maximum file size 2 MB. A header row is recommended.</div>
                                <div class="mt-3">
                                    <a class="btn btn-sm btn-light" href="<?= e(base_url('api/websites/import.php?template=1')) ?>"><i class="bi bi-download" aria-hidden="true"></i> Download CSV template</a>
                                </div>
                            </div>
                            <aside class="import-aside" id="csvFormat">
                                <h3>Supported columns</h3>
                                <dl class="kv-list fs-13 mb-2">
                                    <dt>URL</dt><dd>Required</dd>
                                    <dt>Website Name</dt><dd>Optional</dd>
                                    <dt>Client Name</dt><dd>Optional</dd>
                                    <dt>Type</dt><dd>wordpress · woocommerce · other</dd>
                                    <dt>Check Interval</dt><dd>1, 2, 5, 10, 15 or 30</dd>
                                </dl>
                                <p class="fs-12 text-muted mb-0">Unknown columns are ignored.</p>
                            </aside>
                        </div>
                        <div class="sw-form-actions mt-4">
                            <div class="me-auto"></div>
                            <button type="submit" class="btn btn-primary" id="csvSubmit"><i class="bi bi-upload" aria-hidden="true"></i> Upload and import</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="sw-card d-none mb-4" id="importReport">
        <div class="sw-card-header">
            <div><h3>Import results</h3><p class="sub" id="importSummary"></p></div>
            <a class="btn btn-sm btn-light" href="<?= e(base_url('admin/websites.php')) ?>">Go to websites</a>
        </div>
        <div class="sw-card-body import-report" id="importReportBody"></div>
    </div>

    <details class="sw-disclosure">
        <summary>How import works <span class="hint">validation and duplicate rules</span></summary>
        <div class="sw-disclosure-body fs-13 text-muted">
            <p>Every entry is validated and normalised — <span class="code-inline">example.com</span> becomes <span class="code-inline">https://example.com/</span>.</p>
            <ul class="ps-3 mb-3">
                <li>Websites already monitored are reported as <b>duplicates</b> and skipped.</li>
                <li>Malformed addresses are reported as <b>invalid</b>.</li>
                <li>Targets resolving to private or internal networks are rejected for security.</li>
                <li>Nothing is discarded silently — the results list every line with a reason.</li>
            </ul>
            <p class="mb-0">Imported websites use the default checks and alert rules. The first monitoring run happens on the next cron cycle.</p>
        </div>
    </details>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
