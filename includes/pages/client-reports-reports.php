<?php

declare(strict_types=1);

/**
 * Client reports → Reports (client-reports.js).
 */
?>
<h2 class="visually-hidden">Summary</h2>
<div class="summary-strip cols-4 mb-3" id="crSummary">
    <div class="summary-item"><div class="l">Reports</div><div class="v" data-sum="reports">—</div><div class="s">saved for clients</div></div>
    <div class="summary-item"><div class="l">Live links</div><div class="v" data-sum="active">—</div><div class="s">clients can open now</div></div>
    <div class="summary-item"><div class="l">Views</div><div class="v" data-sum="views">—</div><div class="s">of all share links</div></div>
    <div class="summary-item"><div class="l">Brand kits</div><div class="v" data-sum="brands">—</div><div class="s"><a href="<?= e(base_url('admin/client-reports.php?tab=brands')) ?>">Manage brands</a></div></div>
</div>

<section class="sw-card" aria-labelledby="crHeading">
    <div class="sw-card-header">
        <div>
            <h3 id="crHeading">Saved reports</h3>
            <p class="sub">Links always show the latest figures for their period. Rolling periods such as “Last 30 days” move forward on their own.</p>
        </div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table">
            <thead>
                <tr>
                    <th scope="col">Report</th>
                    <th scope="col" class="hide-mobile">Brand</th>
                    <th scope="col">Period</th>
                    <th scope="col">Share link</th>
                    <th scope="col" class="num hide-mobile">Views</th>
                    <th scope="col" class="actions"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody id="crBody"></tbody>
        </table>
    </div>
</section>

<div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="reportForm" novalidate autocomplete="off">
            <div class="modal-header">
                <h2 class="modal-title" id="reportModalTitle">New client report</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="">

                <fieldset class="sw-fieldset">
                    <legend>Report</legend>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" for="cr-title">Title</label>
                            <input type="text" class="form-control" id="cr-title" name="title" maxlength="150" required placeholder="e.g. Monthly website report">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="cr-brand">Brand</label>
                            <select class="form-select" id="cr-brand" name="brand_id"></select>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="sw-fieldset">
                    <legend>Websites</legend>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="cr-client">Client</label>
                            <select class="form-select" id="cr-client" name="client_name"></select>
                        </div>
                        <div class="col-md-6">
                            <span class="form-label d-block">Include</span>
                            <div class="segmented" role="group" aria-label="Websites to include" id="crScope">
                                <button type="button" data-scope="all" class="active">All of them</button>
                                <button type="button" data-scope="pick">Choose websites</button>
                            </div>
                        </div>
                    </div>
                    <div class="cr-site-picker mt-3" id="crSites" hidden></div>
                    <input type="hidden" name="website_ids" value="">
                    <p class="desc mt-2 mb-0" id="crScopeHint"></p>
                </fieldset>

                <fieldset class="sw-fieldset">
                    <legend>Period</legend>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label" for="cr-period">Covers</label>
                            <select class="form-select" id="cr-period" name="period"></select>
                        </div>
                        <div class="col-md-7" id="crCustomDates" hidden>
                            <div class="row g-2">
                                <div class="col-6"><label class="form-label" for="cr-from">First day</label><input type="date" class="form-control" id="cr-from" name="date_from"></div>
                                <div class="col-6"><label class="form-label" for="cr-to">Last day</label><input type="date" class="form-control" id="cr-to" name="date_to"></div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="sw-fieldset">
                    <legend>Sections</legend>
                    <div class="option-grid" id="crSections"></div>
                    <input type="hidden" name="sections" value="">
                </fieldset>

                <fieldset class="sw-fieldset">
                    <legend>Message to your client</legend>
                    <p class="desc">Optional. Shown under the summary, e.g. what you worked on this month.</p>
                    <textarea class="form-control" id="cr-intro" name="intro" rows="3" maxlength="2000" placeholder="Hi Sarah, here is this month's report. We renewed the SSL certificate on 12 September and…"></textarea>
                </fieldset>

                <fieldset class="sw-fieldset mb-0">
                    <legend>Share link</legend>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="cr-active" name="is_active" checked>
                                <label class="form-check-label" for="cr-active">Anyone with the link can open the report</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cr-expires">Link expires</label>
                            <input type="date" class="form-control" id="cr-expires" name="expires_on">
                            <div class="form-text">Leave empty for a link that keeps working.</div>
                        </div>
                    </div>
                </fieldset>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> <span data-submit-label>Create report</span></button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="shareModalTitle">Share report</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="fs-13 text-muted mb-3" id="shareIntro"></p>
                <label class="form-label" for="shareUrl">Report page</label>
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="shareUrl" readonly>
                    <button type="button" class="btn btn-light" data-copy="#shareUrl"><i class="bi bi-clipboard" aria-hidden="true"></i> Copy</button>
                </div>
                <label class="form-label" for="sharePdfUrl">Direct PDF download</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="sharePdfUrl" readonly>
                    <button type="button" class="btn btn-light" data-copy="#sharePdfUrl"><i class="bi bi-clipboard" aria-hidden="true"></i> Copy</button>
                </div>
                <div class="coverage-note mt-3 mb-0" role="note" id="shareWarning" hidden>
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                    <div id="shareWarningText"></div>
                </div>
            </div>
            <div class="modal-footer">
                <a class="btn btn-light me-auto" id="shareOpen" href="#" target="_blank" rel="noopener" data-no-swap><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open</a>
                <a class="btn btn-light" id="shareEmail" href="#" data-no-swap><i class="bi bi-envelope" aria-hidden="true"></i> Email</a>
                <a class="btn btn-primary" id="sharePdf" href="#" data-no-swap><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Download PDF</a>
            </div>
        </div>
    </div>
</div>
