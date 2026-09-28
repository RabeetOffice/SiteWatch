<?php

declare(strict_types=1);

/**
 * Page shell footer: closes layout, toast container, confirm dialog, command palette, scripts.
 *
 * Scripts marked data-page-script belong to the page and are run again after every in-app navigation;
 * the others (Bootstrap, Chart.js, app.js) load once per session.
 */
?>
        </main>
    </div>
</div>
<div class="sw-backdrop" id="sidebarBackdrop"></div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index:1090"></div>
<?php $flash = \App\Core\App::session()->getFlash('toast'); ?>
<?php if (is_array($flash) && !empty($flash['message'])): ?>
<div data-flash="<?= e($flash['message']) ?>" data-flash-type="<?= e($flash['type'] ?? 'success') ?>" hidden></div>
<?php endif; ?>

<div class="modal fade" id="swConfirmModal" tabindex="-1" aria-labelledby="swConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
        <div class="modal-content">
            <div class="modal-body">
                <div class="d-flex gap-3">
                    <div class="activity-icon tone-danger flex-shrink-0" id="swConfirmIcon" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="min-w-0">
                        <h2 class="mb-1" style="font-size:16px" id="swConfirmTitle">Are you sure?</h2>
                        <p class="text-muted mb-0 fs-13" id="swConfirmMessage">This action cannot be undone.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="swConfirmOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

<div class="sw-palette" id="swPalette" role="dialog" aria-modal="true" aria-label="Search websites and pages" hidden>
    <div class="sw-palette-backdrop" data-palette-close></div>
    <div class="sw-palette-panel">
        <div class="sw-palette-input">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="text" id="swPaletteInput" placeholder="Search websites, pages and actions…" autocomplete="off" spellcheck="false"
                   role="combobox" aria-expanded="true" aria-controls="swPaletteList" aria-autocomplete="list">
            <kbd class="sw-kbd">Esc</kbd>
        </div>
        <ul class="sw-palette-list" id="swPaletteList" role="listbox"></ul>
        <div class="sw-palette-foot"><span><kbd class="sw-kbd">↑</kbd><kbd class="sw-kbd">↓</kbd> move</span><span><kbd class="sw-kbd">Enter</kbd> open</span><span><kbd class="sw-kbd">?</kbd> shortcuts</span></div>
    </div>
</div>

<div class="modal fade" id="swShortcuts" tabindex="-1" aria-labelledby="swShortcutsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="swShortcutsTitle">Keyboard shortcuts</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body"><dl class="sw-shortcut-list" id="swShortcutList"></dl></div>
        </div>
    </div>
</div>

<script id="sw-config" type="application/json"><?= json_out($swConfig) ?></script>
<script id="sw-page-data" type="application/json"><?= json_out($pageData) ?></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php if (!empty($needsCharts)): ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($pageScripts as $script): ?>
<script src="<?= e(asset('js/' . $script)) ?>" data-page-script></script>
<?php endforeach; ?>
</body>
</html>
