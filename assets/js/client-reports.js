/* SiteWatch — client reports and brand kits */
(function () {
    'use strict';

    const esc = SW.escape;
    const state = { reports: [], brands: [] };
    let reportModal = null;
    let shareModal = null;
    let brandModal = null;
    let logoObjectUrl = null;

    // ------------------------------------------------------------------
    // Shared
    // ------------------------------------------------------------------
    async function load() {
        const res = await SW.api('api/client-reports/list.php');
        state.reports = res.data.reports;
        state.brands = res.data.brands;
    }

    async function copy(text) {
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } finally { ta.remove(); }
        }
        SW.toast('Link copied.', 'success');
    }

    function clientOptions(select, selected, emptyLabel) {
        select.innerHTML = '<option value="">' + esc(emptyLabel) + '</option>' + (SW.page.clients || []).map(function (c) {
            return '<option value="' + esc(c) + '"' + (c === selected ? ' selected' : '') + '>' + esc(c) + '</option>';
        }).join('');
    }

    // ------------------------------------------------------------------
    // Reports tab
    // ------------------------------------------------------------------
    function statusPill(r) {
        if (r.status === 'active') return SW.pill('Live', 'success', 'bi-broadcast');
        if (r.status === 'expired') return SW.pill('Expired', 'warning', 'bi-clock-history');
        return SW.pill('Switched off', 'neutral', 'bi-slash-circle');
    }

    function reportRow(r) {
        const share = SW.page.canShare;
        const menu = [
            '<li><a class="dropdown-item" href="' + esc(r.preview_url) + '" target="_blank" rel="noopener" data-no-swap><i class="bi bi-eye"></i> Preview</a></li>',
            '<li><a class="dropdown-item" href="' + esc(r.pdf_url) + '" data-no-swap><i class="bi bi-file-earmark-pdf"></i> Download PDF</a></li>',
        ];
        if (share) {
            menu.push('<li><hr class="dropdown-divider"></li>');
            menu.push('<li><button type="button" class="dropdown-item" data-cr="edit" data-id="' + r.id + '"><i class="bi bi-pencil"></i> Edit</button></li>');
            menu.push('<li><button type="button" class="dropdown-item" data-cr="duplicate" data-id="' + r.id + '"><i class="bi bi-copy"></i> Duplicate</button></li>');
            menu.push('<li><button type="button" class="dropdown-item" data-cr="' + (r.is_active ? 'disable' : 'enable') + '" data-id="' + r.id + '"><i class="bi ' + (r.is_active ? 'bi-slash-circle' : 'bi-broadcast') + '"></i> ' + (r.is_active ? 'Switch link off' : 'Switch link on') + '</button></li>');
            menu.push('<li><button type="button" class="dropdown-item" data-cr="regenerate" data-id="' + r.id + '"><i class="bi bi-arrow-repeat"></i> New link address</button></li>');
            menu.push('<li><hr class="dropdown-divider"></li>');
            menu.push('<li><button type="button" class="dropdown-item text-danger" data-cr="delete" data-id="' + r.id + '"><i class="bi bi-trash"></i> Delete</button></li>');
        }
        const brand = r.brand_name
            ? esc(r.brand_name)
            : '<span class="text-muted">' + (r.client_name ? 'Client default' : 'Default') + '</span>';
        return '<tr>' +
            '<td><div class="fw-600">' + esc(r.title) + '</div><div class="fs-12 text-muted">' + esc(r.scope_label) + '</div></td>' +
            '<td class="hide-mobile">' + brand + '</td>' +
            '<td><div class="nowrap">' + esc(r.period_label) + '</div><div class="fs-12 text-muted nowrap">' + esc(r.range_label) + '</div></td>' +
            '<td>' + statusPill(r) + '<div class="fs-12 text-muted mt-1">' + (r.expires_on ? 'Until ' + esc(r.expires_label) : 'No expiry') + '</div></td>' +
            '<td class="num hide-mobile"><div>' + SW.fmt.num(r.view_count) + '</div>' + (r.last_viewed ? '<div class="fs-12 text-muted">' + esc(r.last_viewed) + '</div>' : '') + '</td>' +
            '<td class="actions"><div class="row-actions">' +
                '<button type="button" class="btn btn-sm btn-light" data-cr="share" data-id="' + r.id + '"><i class="bi bi-share" aria-hidden="true"></i> Share</button>' +
                '<div class="dropdown"><button type="button" class="btn-icon btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for ' + esc(r.title) + '"><i class="bi bi-three-dots" aria-hidden="true"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end">' + menu.join('') + '</ul></div>' +
            '</div></td></tr>';
    }

    function renderReports() {
        const body = document.getElementById('crBody');
        const sum = {
            reports: state.reports.length,
            active: state.reports.filter(function (r) { return r.status === 'active'; }).length,
            views: state.reports.reduce(function (n, r) { return n + r.view_count; }, 0),
            brands: state.brands.length,
        };
        Object.keys(sum).forEach(function (k) {
            const el = document.querySelector('#crSummary [data-sum="' + k + '"]');
            if (el) el.textContent = SW.fmt.num(sum[k]);
        });
        if (!state.reports.length) {
            const cta = SW.page.canShare ? '<button type="button" class="btn btn-primary btn-sm" data-cr="new"><i class="bi bi-plus-lg"></i> Create your first report</button>' : '';
            body.innerHTML = '<tr><td colspan="6">' + SW.emptyState('bi-file-earmark-richtext', 'No client reports yet', 'Create a branded report, then send your client the link or the PDF.', cta) + '</td></tr>';
            return;
        }
        body.innerHTML = state.reports.map(reportRow).join('');
        SW.dropdowns(body);
    }

    // Editor ------------------------------------------------------------
    /** SW.showErrors, plus bringing the first message into view (hidden fields cannot take focus). */
    function showErrors(form, errors) {
        SW.showErrors(form, errors);
        const first = form.querySelector('.invalid-feedback.dynamic');
        if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    function formEl(name) { return document.querySelector('#reportForm [name="' + name + '"]'); }

    function scope() {
        const active = document.querySelector('#crScope button.active');
        return active ? active.getAttribute('data-scope') : 'all';
    }

    function setScope(value) {
        SW.qsa('#crScope button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-scope') === value); });
        renderSites();
    }

    function renderSites(checked) {
        const client = formEl('client_name').value;
        const box = document.getElementById('crSites');
        const keep = checked || SW.qsa('input[data-site]:checked', box).map(function (c) { return parseInt(c.value, 10); });
        const sites = (SW.page.websites || []).filter(function (w) { return !client || w.client_name === client; });
        box.hidden = scope() !== 'pick';
        box.innerHTML = sites.length ? sites.map(function (w) {
            return '<label class="cr-site"><input class="form-check-input" type="checkbox" data-site value="' + w.id + '"' + (keep.indexOf(w.id) !== -1 ? ' checked' : '') + '>' +
                '<span><span class="t">' + esc(w.name) + '</span><span class="d">' + esc(w.domain) + (client ? '' : (w.client_name ? ' · ' + esc(w.client_name) : '')) + '</span></span></label>';
        }).join('') : '<p class="text-muted fs-13 mb-0">No websites for this client.</p>';
        const hint = document.getElementById('crScopeHint');
        hint.textContent = scope() === 'pick'
            ? 'Only the ticked websites appear in the report.'
            : (client ? 'All ' + sites.length + ' websites of ' + client + ', including ones you add later.' : 'Every monitored website (' + sites.length + '), including ones you add later.');
    }

    function renderSections(selected) {
        document.getElementById('crSections').innerHTML = (SW.page.sections || []).map(function (s) {
            return '<label class="option-card"><input class="form-check-input" type="checkbox" data-section="' + esc(s.key) + '"' + (selected.indexOf(s.key) !== -1 ? ' checked' : '') + '>' +
                '<span><span class="t">' + esc(s.label) + '</span><span class="d">' + esc(s.description) + '</span></span></label>';
        }).join('');
    }

    function renderBrandSelect(selected) {
        const select = formEl('brand_id');
        select.innerHTML = '<option value="">Automatic (client’s brand, if any)</option>' + state.brands.map(function (b) {
            return '<option value="' + b.id + '"' + (b.id === selected ? ' selected' : '') + '>' + esc(b.name) + (b.client_name ? ' · ' + esc(b.client_name) : '') + '</option>';
        }).join('');
    }

    function togglePeriod() {
        document.getElementById('crCustomDates').hidden = formEl('period').value !== 'custom';
    }

    function openReport(r, duplicate) {
        const form = document.getElementById('reportForm');
        form.reset();
        SW.showErrors(form, {});
        const isEdit = !!r && !duplicate;
        document.getElementById('reportModalTitle').textContent = isEdit ? 'Edit report' : (duplicate ? 'Duplicate report' : 'New client report');
        form.querySelector('[data-submit-label]').textContent = isEdit ? 'Save report' : 'Create report';
        formEl('id').value = isEdit ? r.id : '';
        formEl('title').value = r ? (duplicate ? r.title + ' (copy)' : r.title) : 'Monthly website report';
        renderBrandSelect(r ? r.brand_id : null);
        clientOptions(formEl('client_name'), r ? r.client_name : '', 'All clients');
        const period = formEl('period');
        period.innerHTML = Object.keys(SW.page.periods).map(function (k) {
            return '<option value="' + esc(k) + '">' + esc(SW.page.periods[k]) + '</option>';
        }).join('');
        period.value = r ? r.period : 'last_month';
        formEl('date_from').value = r && r.date_from ? r.date_from : '';
        formEl('date_to').value = r && r.date_to ? r.date_to : '';
        togglePeriod();
        renderSections(r ? r.sections : SW.page.sections.map(function (s) { return s.key; }));
        formEl('intro').value = r ? r.intro : '';
        formEl('is_active').checked = r ? r.is_active : true;
        formEl('expires_on').value = r && r.expires_on && !duplicate ? r.expires_on : '';
        const ids = r ? r.website_ids : [];
        SW.qsa('#crScope button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-scope') === (ids.length ? 'pick' : 'all')); });
        renderSites(ids);
        reportModal.show();
        setTimeout(function () { formEl('title').focus(); }, 200);
    }

    async function submitReport(ev) {
        ev.preventDefault();
        const form = ev.currentTarget;
        const btn = form.querySelector('[type="submit"]');
        SW.showErrors(form, {});
        const ids = scope() === 'pick' ? SW.qsa('#crSites input[data-site]:checked').map(function (c) { return parseInt(c.value, 10); }) : [];
        if (scope() === 'pick' && !ids.length) {
            showErrors(form, { website_ids: 'Tick at least one website, or include all of them.' });
            return;
        }
        SW.setLoading(btn, true, 'Saving…');
        try {
            const res = await SW.api('api/client-reports/save.php', {
                method: 'POST',
                body: {
                    id: formEl('id').value,
                    title: formEl('title').value,
                    brand_id: formEl('brand_id').value,
                    client_name: formEl('client_name').value,
                    website_ids: ids,
                    period: formEl('period').value,
                    date_from: formEl('date_from').value,
                    date_to: formEl('date_to').value,
                    sections: SW.qsa('#crSections input[data-section]:checked').map(function (c) { return c.getAttribute('data-section'); }),
                    intro: formEl('intro').value,
                    is_active: formEl('is_active').checked,
                    expires_on: formEl('expires_on').value,
                },
            });
            reportModal.hide();
            SW.toast(res.message, 'success');
            await load();
            renderReports();
            if (!formEl('id').value) openShare(res.data.report);
        } catch (e) {
            showErrors(form, e.errors || {});
            SW.toast(e.message, 'danger');
        } finally {
            SW.setLoading(btn, false);
        }
    }

    function openShare(r) {
        document.getElementById('shareModalTitle').textContent = 'Share “' + r.title + '”';
        document.getElementById('shareIntro').textContent = 'Anyone with the link can open this report without signing in. ' + (r.period === 'custom'
            ? 'It covers ' + r.range_label + '.'
            : 'It shows the ' + r.period_label.toLowerCase() + ' (now ' + r.range_label + ') and moves forward by itself.');
        document.getElementById('shareUrl').value = r.share_url;
        document.getElementById('sharePdfUrl').value = r.share_pdf_url;
        document.getElementById('shareOpen').href = r.share_url;
        document.getElementById('sharePdf').href = r.pdf_url;
        const subject = r.title + ' · ' + r.range_label;
        document.getElementById('shareEmail').href = 'mailto:?subject=' + encodeURIComponent(subject) +
            '&body=' + encodeURIComponent('Hello,\n\nYour website report for ' + r.range_label + ' is ready:\n' + r.share_url + '\n\nYou can also download it as a PDF from that page.\n');
        const warn = document.getElementById('shareWarning');
        warn.hidden = r.status === 'active';
        document.getElementById('shareWarningText').textContent = r.status === 'expired'
            ? 'This link has expired, so people who open it see “not available”. Edit the report to extend it.'
            : 'This link is switched off, so people who open it see “not available”. Switch it on from the report menu.';
        shareModal.show();
    }

    async function reportAction(action, id) {
        if (action === 'new') { openReport(null, false); return; }
        const r = state.reports.find(function (x) { return x.id === id; });
        if (!r) return;
        if (action === 'share') { openShare(r); return; }
        if (action === 'edit') { openReport(r, false); return; }
        if (action === 'duplicate') { openReport(r, true); return; }
        if (action === 'regenerate') {
            const ok = await SW.confirm({ title: 'Create a new link?', message: 'The current link for “' + r.title + '” stops working straight away. Send the new link to anyone who should still see the report.', confirmText: 'Create new link', danger: false, icon: 'bi-arrow-repeat' });
            if (!ok) return;
        }
        if (action === 'delete') {
            const ok = await SW.confirm({ title: 'Delete “' + r.title + '”?', message: 'The report and its share link are removed. People who open the link will see “not available”.', confirmText: 'Delete report' });
            if (!ok) return;
        }
        try {
            const res = await SW.api('api/client-reports/manage.php', { method: 'POST', body: { id: id, action: action } });
            SW.toast(res.message, 'success');
            await load();
            renderReports();
            if (action === 'regenerate') openShare(res.data.report);
        } catch (e) { SW.toast(e.message, 'danger'); }
    }

    function initReports() {
        reportModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('reportModal'));
        shareModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('shareModal'));
        const add = document.getElementById('btnAddReport');
        if (add) add.addEventListener('click', function () { openReport(null, false); });
        document.getElementById('reportForm').addEventListener('submit', submitReport);
        formEl('period').addEventListener('change', togglePeriod);
        formEl('client_name').addEventListener('change', function () { renderSites([]); });
        document.getElementById('crScope').addEventListener('click', function (ev) {
            const b = ev.target.closest('button[data-scope]');
            if (b) setScope(b.getAttribute('data-scope'));
        });
        document.getElementById('shareModal').addEventListener('click', function (ev) {
            const b = ev.target.closest('[data-copy]');
            if (b) copy(document.querySelector(b.getAttribute('data-copy')).value);
        });
        document.addEventListener('click', function (ev) {
            const el = ev.target.closest('[data-cr]');
            if (el) { ev.preventDefault(); reportAction(el.getAttribute('data-cr'), parseInt(el.getAttribute('data-id') || '0', 10)); }
        });
        document.getElementById('crBody').innerHTML = SW.skeletonRows(6, 3);
        load().then(renderReports).catch(function (e) {
            document.getElementById('crBody').innerHTML = '<tr><td colspan="6">' + SW.emptyState('bi-wifi-off', 'Unable to load reports', e.message) + '</td></tr>';
        });
    }

    // ------------------------------------------------------------------
    // Brand kits tab
    // ------------------------------------------------------------------
    function brandCard(b) {
        const logo = b.logo_url
            ? '<img src="' + esc(b.logo_url) + '" alt="' + esc(b.name) + ' logo">'
            : '<span class="cr-wordmark" style="color:' + esc(b.accent_color) + '">' + esc(b.name) + '</span>';
        const actions = SW.page.canShare
            ? '<button type="button" class="btn btn-sm btn-light" data-brand="edit" data-id="' + b.id + '">Edit</button>' +
              '<button type="button" class="btn-icon btn-sm" data-brand="delete" data-id="' + b.id + '" aria-label="Delete ' + esc(b.name) + '"><i class="bi bi-trash" aria-hidden="true"></i></button>'
            : '';
        return '<article class="sw-card cr-brand-card">' +
            '<div class="cr-brand-logo"><div class="cr-brand-stripe" style="background:' + esc(b.primary_color) + '"></div>' + logo + '</div>' +
            '<div class="cr-brand-body">' +
                '<div class="d-flex align-items-start gap-2"><div class="flex-grow-1 min-w-0"><h3 class="cr-brand-name">' + esc(b.name) + '</h3>' +
                '<div class="fs-12 text-muted">' + (b.client_name ? 'Default for ' + esc(b.client_name) : 'Not linked to a client') + '</div></div>' +
                '<div class="cr-swatches"><span style="background:' + esc(b.primary_color) + '" title="Brand colour ' + esc(b.primary_color) + '"></span><span style="background:' + esc(b.accent_color) + '" title="Table colour ' + esc(b.accent_color) + '"></span></div></div>' +
                '<div class="cr-brand-meta fs-12 text-muted">' +
                    '<span><i class="bi bi-file-earmark-richtext"></i> ' + b.report_count + ' report' + (b.report_count === 1 ? '' : 's') + '</span>' +
                    (b.white_label ? '<span><i class="bi bi-eye-slash"></i> White label</span>' : '') +
                    (b.prepared_by ? '<span><i class="bi bi-person"></i> ' + esc(b.prepared_by) + '</span>' : '') +
                '</div>' +
                '<div class="row-actions justify-content-end mt-2">' + actions + '</div>' +
            '</div></article>';
    }

    function renderBrands() {
        const grid = document.getElementById('brandGrid');
        if (!state.brands.length) {
            const cta = SW.page.canShare ? '<button type="button" class="btn btn-primary btn-sm" data-brand="new"><i class="bi bi-plus-lg"></i> Create a brand kit</button>' : '';
            grid.innerHTML = '<div class="sw-card cr-brand-empty">' + SW.emptyState('bi-palette', 'No brand kits yet', 'Add a client’s logo and colours so their reports look like their own.', cta) + '</div>';
            return;
        }
        grid.innerHTML = state.brands.map(brandCard).join('');
    }

    function brandEl(name) { return document.querySelector('#brandForm [name="' + name + '"]'); }

    function validHex(v) { return /^#[0-9a-f]{6}$/i.test(v); }

    function updatePreview() {
        const p = function (k) { return document.querySelector('#brandPreview [data-p="' + k + '"]'); };
        const primary = validHex(brandEl('primary_color').value) ? brandEl('primary_color').value : SW.page.defaults.primary;
        const accent = validHex(brandEl('accent_color').value) ? brandEl('accent_color').value : SW.page.defaults.accent;
        const name = brandEl('name').value.trim() || 'Your client';
        p('rule').style.background = primary;
        p('eyebrow').style.color = primary;
        SW.qsa('#brandPreview [data-p="bar"]').forEach(function (el) { el.style.background = primary; });
        p('chart').style.setProperty('--cr-c', primary);
        p('th').style.background = accent;
        p('th').style.color = contrastText(accent);
        p('name').textContent = name;
        const img = document.querySelector('#brandLogoPreview img');
        p('logo').innerHTML = img ? '<img src="' + esc(img.src) + '" alt="">' : '<span style="color:' + esc(accent) + '">' + esc(name) + '</span>';
        const bits = [name];
        if (brandEl('footer_text').value.trim()) bits.push(brandEl('footer_text').value.trim());
        p('foot').textContent = bits.join(' · ') + (brandEl('white_label').checked ? '' : '  ·  Monitoring by SiteWatch');
    }

    function contrastText(hex) {
        const n = parseInt(hex.slice(1), 16);
        const lin = function (c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
        const l = 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);
        return l > 0.45 ? '#0F172A' : '#FFFFFF';
    }

    function setLogoPreview(src) {
        const box = document.getElementById('brandLogoPreview');
        box.innerHTML = src ? '<img src="' + esc(src) + '" alt="Logo preview">' : '<span class="text-muted fs-13">No logo</span>';
        document.getElementById('brandLogoRemove').hidden = !src;
        updatePreview();
    }

    function syncColor(name) {
        const text = brandEl(name);
        const picker = document.getElementById('b-' + (name === 'primary_color' ? 'primary' : 'accent') + '-picker');
        picker.addEventListener('input', function () { text.value = picker.value.toUpperCase(); updatePreview(); });
        text.addEventListener('input', function () { if (validHex(text.value)) picker.value = text.value; updatePreview(); });
    }

    function openBrand(b) {
        const form = document.getElementById('brandForm');
        form.reset();
        SW.showErrors(form, {});
        if (logoObjectUrl) { URL.revokeObjectURL(logoObjectUrl); logoObjectUrl = null; }
        document.getElementById('brandModalTitle').textContent = b ? 'Edit ' + b.name : 'New brand kit';
        form.querySelector('[data-submit-label]').textContent = b ? 'Save brand' : 'Create brand';
        brandEl('id').value = b ? b.id : '';
        brandEl('name').value = b ? b.name : '';
        clientOptions(brandEl('client_name'), b ? b.client_name : '', 'Not linked');
        brandEl('primary_color').value = b ? b.primary_color : SW.page.defaults.primary;
        brandEl('accent_color').value = b ? b.accent_color : SW.page.defaults.accent;
        document.getElementById('b-primary-picker').value = brandEl('primary_color').value;
        document.getElementById('b-accent-picker').value = brandEl('accent_color').value;
        brandEl('prepared_by').value = b ? b.prepared_by : SW.page.defaults.preparedBy;
        brandEl('website').value = b ? b.website : '';
        brandEl('email').value = b ? b.email : '';
        brandEl('phone').value = b ? b.phone : '';
        brandEl('footer_text').value = b ? b.footer_text : '';
        brandEl('white_label').checked = b ? b.white_label : false;
        brandEl('remove_logo').value = '0';
        setLogoPreview(b ? b.logo_url : null);
        brandModal.show();
        setTimeout(function () { brandEl('name').focus(); }, 200);
    }

    async function submitBrand(ev) {
        ev.preventDefault();
        const form = ev.currentTarget;
        const btn = form.querySelector('[type="submit"]');
        SW.showErrors(form, {});
        const data = new FormData(form);
        data.set('white_label', brandEl('white_label').checked ? '1' : '0');
        const file = brandEl('logo').files[0];
        if (!file) data.delete('logo');
        SW.setLoading(btn, true, 'Saving…');
        try {
            const res = await SW.api('api/brands/save.php', { method: 'POST', body: data });
            brandModal.hide();
            SW.toast(res.message, 'success');
            await load();
            renderBrands();
        } catch (e) {
            SW.showErrors(form, e.errors || {});
            SW.toast(e.message, 'danger');
        } finally {
            SW.setLoading(btn, false);
        }
    }

    async function brandAction(action, id) {
        if (action === 'new') { openBrand(null); return; }
        const b = state.brands.find(function (x) { return x.id === id; });
        if (!b) return;
        if (action === 'edit') { openBrand(b); return; }
        if (action === 'delete') {
            const used = b.report_count ? ' ' + b.report_count + ' report' + (b.report_count === 1 ? ' uses' : 's use') + ' it and will switch to the client’s brand or the default.' : '';
            const ok = await SW.confirm({ title: 'Delete the ' + b.name + ' brand?', message: 'The brand and its logo are removed.' + used, confirmText: 'Delete brand' });
            if (!ok) return;
            try {
                const res = await SW.api('api/brands/delete.php', { method: 'POST', body: { id: id } });
                SW.toast(res.message, 'success');
                await load();
                renderBrands();
            } catch (e) { SW.toast(e.message, 'danger'); }
        }
    }

    function initBrands() {
        brandModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('brandModal'));
        const add = document.getElementById('btnAddBrand');
        if (add) add.addEventListener('click', function () { openBrand(null); });
        const form = document.getElementById('brandForm');
        form.addEventListener('submit', submitBrand);
        syncColor('primary_color');
        syncColor('accent_color');
        ['name', 'footer_text'].forEach(function (n) { brandEl(n).addEventListener('input', updatePreview); });
        brandEl('white_label').addEventListener('change', updatePreview);
        brandEl('logo').addEventListener('change', function () {
            const file = brandEl('logo').files[0];
            if (logoObjectUrl) URL.revokeObjectURL(logoObjectUrl);
            logoObjectUrl = file ? URL.createObjectURL(file) : null;
            brandEl('remove_logo').value = '0';
            if (logoObjectUrl) setLogoPreview(logoObjectUrl);
        });
        document.getElementById('brandLogoRemove').addEventListener('click', function () {
            brandEl('logo').value = '';
            brandEl('remove_logo').value = '1';
            setLogoPreview(null);
        });
        document.addEventListener('click', function (ev) {
            const el = ev.target.closest('[data-brand]');
            if (el) { ev.preventDefault(); brandAction(el.getAttribute('data-brand'), parseInt(el.getAttribute('data-id') || '0', 10)); }
        });
        document.getElementById('brandGrid').innerHTML = '<div class="sw-card p-4"><div class="skeleton skeleton-line" style="width:60%">&nbsp;</div></div>';
        load().then(renderBrands).catch(function (e) {
            document.getElementById('brandGrid').innerHTML = '<div class="sw-card">' + SW.emptyState('bi-wifi-off', 'Unable to load brands', e.message) + '</div>';
        });
    }

    document.addEventListener('sw:ready', function () {
        if (document.getElementById('crBody')) initReports();
        if (document.getElementById('brandGrid')) initBrands();
    });
})();
