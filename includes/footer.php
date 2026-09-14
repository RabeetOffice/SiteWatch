<?php

declare(strict_types=1);

/**
 * Page shell footer: closes layout, toast container, confirm modal, scripts.
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

<script id="sw-config" type="application/json"><?= json_out($swConfig) ?></script>
<script id="sw-page-data" type="application/json"><?= json_out($pageData) ?></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<?php if (!empty($needsCharts)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($pageScripts as $script): ?>
<script src="<?= e(asset('js/' . $script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
