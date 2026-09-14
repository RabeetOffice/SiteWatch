/* SiteWatch — bulk import page */
(function () {
    'use strict';

    function outcome(title, tone, icon, items, render) {
        if (!items.length) return '';
        return '<section class="import-outcome"><h3><span class="activity-icon tone-' + tone + '" aria-hidden="true"><i class="bi ' + icon + '"></i></span>' +
            SW.escape(title) + '<span class="sw-pill tone-' + tone + '">' + items.length + '</span></h3>' +
            '<ul class="list-group list-group-flush">' + items.map(render).join('') + '</ul></section>';
    }

    function renderReport(data) {
        const card = document.getElementById('importReport');
        const summary = document.getElementById('importSummary');
        const body = document.getElementById('importReportBody');
        if (!card) return;
        card.classList.remove('d-none');
        const c = data.counts;
        summary.textContent = c.total + (c.total === 1 ? ' entry processed' : ' entries processed');
        body.innerHTML =
            '<div class="summary-strip mb-4">' +
            '<div class="summary-item"><div class="l">Imported</div><div class="v text-success">' + c.imported + '</div><div class="s">now monitored</div></div>' +
            '<div class="summary-item"><div class="l">Duplicates</div><div class="v">' + c.duplicates + '</div><div class="s">already monitored</div></div>' +
            '<div class="summary-item"><div class="l">Invalid</div><div class="v' + (c.invalid ? ' text-danger' : '') + '">' + c.invalid + '</div><div class="s">not a usable URL</div></div>' +
            '<div class="summary-item"><div class="l">Rejected</div><div class="v' + (c.failed ? ' text-danger' : '') + '">' + c.failed + '</div><div class="s">blocked or failed</div></div>' +
            '</div>' +
            outcome('Imported', 'success', 'bi-check-lg', data.report.imported, function (i) {
                return '<li class="list-group-item"><a href="' + SW.url('admin/website-details.php', { id: i.id }) + '">' + SW.escape(i.name) + '</a>' +
                    '<span class="text-muted mono break-anywhere">' + SW.escape(i.url) + '</span></li>';
            }) +
            outcome('Duplicates — skipped', 'warning', 'bi-files', data.report.duplicates, function (i) {
                return '<li class="list-group-item"><span class="mono break-anywhere">' + SW.escape(i.input) + '</span><span class="text-muted">' + SW.escape(i.reason) + '</span></li>';
            }) +
            outcome('Invalid entries', 'danger', 'bi-x-lg', data.report.invalid, function (i) {
                return '<li class="list-group-item"><span class="mono break-anywhere">' + SW.escape(i.input) + '</span><span class="text-danger">' + SW.escape(i.reason) + '</span></li>';
            }) +
            outcome('Rejected entries', 'danger', 'bi-shield-exclamation', data.report.failed, function (i) {
                return '<li class="list-group-item"><span class="mono break-anywhere">' + SW.escape(i.input) + '</span><span class="text-danger">' + SW.escape(i.reason) + '</span></li>';
            });
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
