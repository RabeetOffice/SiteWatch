/* SiteWatch — add / edit website form */
(function () {
    'use strict';

    function normalizePreview(value) {
        value = (value || '').trim();
        if (!value) return null;
        if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(value)) value = 'https://' + value.replace(/^\/+/, '');
        try {
            const u = new URL(value);
            if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
            if (!u.hostname || (u.hostname.indexOf('.') === -1 && u.hostname !== 'localhost')) return null;
            u.hash = '';
            return u.toString();
        } catch (e) { return null; }
    }

    document.addEventListener('sw:ready', function () {
        const form = document.getElementById('websiteForm');
        if (!form) return;
        const mode = form.getAttribute('data-mode');
        const id = parseInt(form.getAttribute('data-id') || '0', 10);
        const urlInput = form.querySelector('[name="url"]');
        const nameInput = form.querySelector('[name="name"]');
        const preview = document.getElementById('urlPreview');
        const submit = document.getElementById('websiteSubmit');

        const updatePreview = function () {
            const normalized = normalizePreview(urlInput.value);
            if (!urlInput.value.trim()) { preview.innerHTML = 'Enter the public address. <span class="code-inline">example.com</span> becomes <span class="code-inline">https://example.com/</span>.'; return; }
            preview.innerHTML = normalized ? 'Will be monitored as <span class="code-inline">' + SW.escape(normalized) + '</span>' : '<span class="text-danger">This does not look like a valid public http(s) URL.</span>';
            if (normalized && mode === 'create' && !nameInput.value.trim()) {
                try { const host = new URL(normalized).hostname.replace(/^www\./, ''); const label = host.split('.')[0].replace(/[-_]+/g, ' '); nameInput.placeholder = label.replace(/\b\w/g, function (c) { return c.toUpperCase(); }); } catch (e) { /* ignore */ }
            }
        };
        urlInput.addEventListener('input', SW.debounce(updatePreview, 150));
        urlInput.addEventListener('blur', function () {
            if (mode === 'create' && !nameInput.value.trim() && nameInput.placeholder && nameInput.placeholder !== 'Northern Star Press') { nameInput.value = nameInput.placeholder; }
        });
        updatePreview();

        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            SW.showErrors(form, {});
            const body = {};
            new FormData(form).forEach(function (value, key) {
                const m = key.match(/^(checks|alerts)\[(\w+)\]$/);
                if (m) { body[m[1]] = body[m[1]] || {}; body[m[1]][m[2]] = value; return; }
                body[key] = value;
            });
            // Unchecked boxes are absent from FormData: send explicit false for each option.
            ['checks', 'alerts'].forEach(function (group) {
                body[group] = body[group] || {};
                SW.qsa('input[name^="' + group + '["]', form).forEach(function (cb) { const k = cb.name.match(/\[(\w+)\]/)[1]; if (!(k in body[group])) body[group][k] = '0'; });
            });
            if (!form.querySelector('[name="monitoring_enabled"]').checked) body.monitoring_enabled = '0';
            if (mode === 'edit') body.id = id;
            if (mode === 'create') body.check_now = form.querySelector('[name="check_now"]') && form.querySelector('[name="check_now"]').checked ? '1' : '0';

            SW.setLoading(submit, true, mode === 'create' ? (body.check_now === '1' ? 'Adding & checking…' : 'Adding…') : 'Saving…');
            try {
                const res = await SW.api(mode === 'create' ? 'api/websites/store.php' : 'api/websites/update.php', { method: 'POST', body: body });
                (res.data.warnings || []).forEach(function (w) { SW.toast(w, 'warning', { delay: 8000 }); });
                if (res.data.first_check) {
                    const r = res.data.first_check;
                    SW.toast('First check: ' + r.status_label + (r.http_status ? ' · HTTP ' + r.http_status : '') + (r.response_time !== null ? ' · ' + SW.fmt.ms(r.response_time) : ''), r.severity === 'down' ? 'danger' : r.severity === 'warning' ? 'warning' : 'success');
                }
                SW.toast(res.message, 'success');
                setTimeout(function () { window.location.href = res.data.redirect; }, 600);
            } catch (e) {
                SW.setLoading(submit, false);
                if (e.errors && Object.keys(e.errors).length) { SW.showErrors(form, e.errors); SW.toast(e.message, 'danger'); } else { SW.toast(e.message, 'danger'); }
            }
        });
    });
})();
