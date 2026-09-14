/* SiteWatch — bulk import page */
(function () {
    'use strict';

    function renderReport(data) {
        const card = document.getElementById('importReport');
        const summary = document.getElementById('importSummary');
        const body = document.getElementById('importReportBody');
        if (!card) return;
        card.classList.remove('d-none');
        const c = data.counts;
        summary.textContent = c.total + ' entries processed';
        const section = function (title, tone, icon, items, render) {
            if (!items.length) return '';
            return '<div class="mb-3"><div class="d-flex align-items-center gap-2 mb-2"><span class="activity-icon tone-' + tone + '" style="width:28px;height:28px;font-size:13px"><i class="bi ' + icon + '"></i></span><b>' + title + '</b><span class="sw-pill tone-' + tone + '">' + items.length + '</span></div><ul class="list-group list-group-flush">' + items.map(render).join('') + '</ul></div>';
        };
        body.innerHTML =
            '<div class="row g-2 mb-4">' +
            '<div class="col-6 col-md-3"><div class="overview-item"><div class="l">Imported</div><div class="v text-success">' + c.imported + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="overview-item"><div class="l">Duplicates</div><div class="v text-warning">' + c.duplicates + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="overview-item"><div class="l">Invalid URLs</div><div class="v text-danger">' + c.invalid + '</div></div></div>' +
            '<div class="col-6 col-md-3"><div class="overview-item"><div class="l">Failed</div><div class="v text-danger">' + c.failed + '</div></div></div></div>' +
            section('Imported', 'success', 'bi-check-lg', data.report.imported, function (i) { return '<li class="list-group-item d-flex justify-content-between gap-2"><span><a href="' + SW.url('admin/website-details.php', { id: i.id }) + '">' + SW.escape(i.name) + '</a> <span class="text-muted">' + SW.escape(i.url) + '</span></span></li>'; }) +
            section('Duplicates (skipped)', 'warning', 'bi-files', data.report.duplicates, function (i) { return '<li class="list-group-item d-flex justify-content-between gap-2"><span class="mono">' + SW.escape(i.input) + '</span><span class="text-muted">' + SW.escape(i.reason) + '</span></li>'; }) +
            section('Invalid entries', 'danger', 'bi-x-lg', data.report.invalid, function (i) { return '<li class="list-group-item d-flex justify-content-between gap-2"><span class="mono">' + SW.escape(i.input) + '</span><span class="text-danger">' + SW.escape(i.reason) + '</span></li>'; }) +
            section('Failed', 'danger', 'bi-bug', data.report.failed, function (i) { return '<li class="list-group-item d-flex justify-content-between gap-2"><span class="mono">' + SW.escape(i.input) + '</span><span class="text-danger">' + SW.escape(i.reason) + '</span></li>'; });
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    document.addEventListener('sw:ready', function () {
        const pasteForm = document.getElementById('pasteForm');
        const csvForm = document.getElementById('csvForm');
        if (pasteForm) pasteForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const btn = document.getElementById('pasteSubmit');
            SW.showErrors(pasteForm, {});
            SW.setLoading(btn, true, 'Importing…');
            try {
                const res = await SW.api('api/websites/import.php', { method: 'POST', body: { mode: 'paste', urls: pasteForm.urls.value, type: pasteForm.type.value, check_interval: pasteForm.check_interval.value, client_name: pasteForm.client_name.value } });
                SW.toast(res.message, res.data.counts.imported > 0 ? 'success' : 'warning', { delay: 6000 });
                renderReport(res.data);
            } catch (e) { SW.showErrors(pasteForm, e.errors || {}); SW.toast(e.message, 'danger'); }
            finally { SW.setLoading(btn, false); }
        });
        if (csvForm) csvForm.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            const btn = document.getElementById('csvSubmit');
            const fileInput = document.getElementById('csvFile');
            SW.showErrors(csvForm, {});
            if (!fileInput.files.length) { SW.showErrors(csvForm, { file: 'Please choose a CSV file.' }); return; }
            const fd = new FormData();
            fd.append('mode', 'csv');
            fd.append('file', fileInput.files[0]);
            SW.setLoading(btn, true, 'Uploading…');
            try {
                const res = await SW.api('api/websites/import.php', { method: 'POST', body: fd });
                SW.toast(res.message, res.data.counts.imported > 0 ? 'success' : 'warning', { delay: 6000 });
                renderReport(res.data);
            } catch (e) { SW.showErrors(csvForm, e.errors || {}); SW.toast(e.message, 'danger'); }
            finally { SW.setLoading(btn, false); }
        });
    });
})();
