<?php

declare(strict_types=1);

/**
 * Client reports → Brand kits (client-reports.js).
 */
?>
<div class="coverage-note mb-4" role="note">
    <i class="bi bi-palette" aria-hidden="true"></i>
    <div>
        <strong>How brands are chosen</strong>
        A report uses the brand picked for it. Otherwise it uses the brand linked to its client, and otherwise a plain
        default. Changes to a brand show on every report that uses it straight away, including links already sent.
    </div>
</div>

<div class="cr-brand-grid" id="brandGrid"></div>

<div class="modal fade" id="brandModal" tabindex="-1" aria-labelledby="brandModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="brandForm" novalidate autocomplete="off">
            <div class="modal-header">
                <h2 class="modal-title" id="brandModalTitle">New brand kit</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <fieldset class="sw-fieldset">
                            <legend>Brand</legend>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="b-name">Name on reports</label>
                                    <input type="text" class="form-control" id="b-name" name="name" maxlength="150" required placeholder="e.g. Acme Publishing">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="b-client">Default for client</label>
                                    <select class="form-select" id="b-client" name="client_name"></select>
                                </div>
                                <div class="col-12">
                                    <span class="form-label d-block">Logo</span>
                                    <div class="cr-logo-drop" id="brandLogoDrop">
                                        <div class="cr-logo-preview" id="brandLogoPreview"><span class="text-muted fs-13">No logo</span></div>
                                        <div class="flex-grow-1">
                                            <input type="file" class="form-control form-control-sm" id="b-logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
                                            <div class="form-text">PNG with a transparent background works best. Wide logos up to 900 × 360 px are kept sharp.</div>
                                            <button type="button" class="btn btn-sm btn-ghost text-danger px-0 mt-1" id="brandLogoRemove" hidden><i class="bi bi-trash" aria-hidden="true"></i> Remove logo</button>
                                        </div>
                                    </div>
                                    <input type="hidden" name="remove_logo" value="0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="b-primary">Brand colour</label>
                                    <div class="input-group cr-color">
                                        <input type="color" class="form-control form-control-color" id="b-primary-picker" aria-label="Pick brand colour">
                                        <input type="text" class="form-control" id="b-primary" name="primary_color" maxlength="7" placeholder="#EA580C">
                                    </div>
                                    <div class="form-text">Headings, charts, the accent line and buttons.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="b-accent">Table colour</label>
                                    <div class="input-group cr-color">
                                        <input type="color" class="form-control form-control-color" id="b-accent-picker" aria-label="Pick table colour">
                                        <input type="text" class="form-control" id="b-accent" name="accent_color" maxlength="7" placeholder="#0F172A">
                                    </div>
                                    <div class="form-text">Table headers. A dark shade reads best.</div>
                                </div>
                            </div>
                        </fieldset>
                        <fieldset class="sw-fieldset mb-0">
                            <legend>Contact &amp; footer</legend>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="b-prepared">Prepared by</label>
                                    <input type="text" class="form-control" id="b-prepared" name="prepared_by" maxlength="150" placeholder="Your agency name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="b-website">Website</label>
                                    <input type="text" class="form-control" id="b-website" name="website" maxlength="255" placeholder="https://example.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="b-email">Email</label>
                                    <input type="email" class="form-control" id="b-email" name="email" maxlength="190" placeholder="support@example.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="b-phone">Phone</label>
                                    <input type="text" class="form-control" id="b-phone" name="phone" maxlength="60">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="b-footer">Footer line</label>
                                    <input type="text" class="form-control" id="b-footer" name="footer_text" maxlength="500" placeholder="e.g. Confidential · prepared for Acme Publishing Ltd">
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="b-white" name="white_label">
                                        <label class="form-check-label" for="b-white">White label: hide “Monitoring by SiteWatch”</label>
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                    </div>
                    <div class="col-lg-5">
                        <span class="form-label d-block">Preview</span>
                        <div class="cr-preview" id="brandPreview" aria-hidden="true">
                            <div class="cr-preview-head">
                                <div class="cr-preview-logo" data-p="logo"></div>
                                <div class="cr-preview-eyebrow" data-p="eyebrow">Website performance report</div>
                            </div>
                            <div class="cr-preview-rule" data-p="rule"></div>
                            <div class="cr-preview-title">Monthly website report</div>
                            <div class="cr-preview-meta">Prepared for <b data-p="name">Brand</b></div>
                            <div class="cr-preview-kpis">
                                <div><span data-p="bar"></span><small>Uptime</small><b>99.98%</b></div>
                                <div><span data-p="bar"></span><small>Incidents</small><b>1</b></div>
                            </div>
                            <div class="cr-preview-chart" data-p="chart"></div>
                            <div class="cr-preview-table">
                                <div class="th" data-p="th"><span>Website</span><span>Uptime</span></div>
                                <div class="td"><span>example.com</span><span>100%</span></div>
                                <div class="td"><span>shop.example.com</span><span>99.95%</span></div>
                            </div>
                            <div class="cr-preview-foot" data-p="foot"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> <span data-submit-label>Create brand</span></button>
            </div>
        </form>
    </div>
</div>
