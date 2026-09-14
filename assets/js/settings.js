/* SiteWatch — settings & notifications pages */
(function () {
    'use strict';

    function serialize(form) {
        const body = {};
        SW.qsa('input, select, textarea', form).forEach(function (el) {
            if (!el.name) return;
            if (el.type === 'checkbox') { body[el.name] = el.checked ? '1' : '0'; return; }
            body[el.name] = el.value;
        });
        return body;
    }

    async function saveForm(form) {
        const section = form.getAttribute('data-section');
        const btn = form.querySelector('button[type="submit"]');
        SW.showErrors(form, {});
        SW.setLoading(btn, true, 'Saving…');
        try {
            const res = await SW.api('api/settings/save.php', { method: 'POST', body: Object.assign({ section: section }, serialize(form)) });
            SW.toast(res.message, 'success');
            // Secrets are never echoed back: clear password fields and update placeholders.
            SW.qsa('input[type="password"]', form).forEach(function (p) { if (p.value) { p.value = ''; p.placeholder = '•••••••••• (saved — leave blank to keep)'; } });
            if (res.data && res.data.reload) setTimeout(function () { window.location.reload(); }, 600);
        } catch (e) {
            SW.showErrors(form, e.errors || {});
            SW.toast(e.message, 'danger');
        } finally { SW.setLoading(btn, false); }
    }

    async function test(channel, btn) {
        SW.setLoading(btn, true, 'Sending…');
        try {
            const res = await SW.api('api/settings/test-' + channel + '.php', { method: 'POST', body: {} });
            SW.toast(res.message, 'success', { delay: 6000 });
            loadLog();
        } catch (e) { SW.toast(e.message, 'danger', { delay: 9000, title: 'Test failed' }); loadLog(); }
        finally { SW.setLoading(btn, false); }
    }

    let logPage = 1;
    async function loadLog() {
        const el = document.getElementById('notifLog');
        if (!el) return;
        try {
            const res = await SW.api('api/notifications/list.php', { query: { page: logPage, per_page: 10 } });
            const d = res.data;
            if (!d.rows.length) { el.innerHTML = SW.emptyState('bi-bell-slash', 'No notifications sent yet.', 'Alerts appear here once an incident is confirmed or a test is sent.'); }
            else {
                el.innerHTML = d.rows.map(function (n) {
                    const tone = n.status === 'sent' ? 'success' : (n.status === 'failed' ? 'danger' : 'muted');
                    const icon = n.channel === 'email' ? 'bi-envelope' : (n.channel === 'telegram' ? 'bi-telegram' : 'bi-bell-slash');
                    return '<div class="activity-item"><span class="activity-icon tone-' + tone + '"><i class="bi ' + icon + '"></i></span><div class="b">' +
                        '<div class="t"><b>' + SW.escape(n.event_label) + '</b>' + (n.website_name ? ' · <a href="' + SW.url('admin/website-details.php', { id: n.website_id }) + '">' + SW.escape(n.website_name) + '</a>' : '') + ' ' + SW.pill(n.status, tone === 'muted' ? 'neutral' : tone) + '</div>' +
                        '<div class="m">' + SW.escape(n.channel_label) + (n.recipient ? ' → ' + SW.escape(n.recipient) : '') + ' · ' + SW.timeAgoEl(n.created_at) + (n.error_message ? '<div class="text-danger">' + SW.escape(n.error_message) + '</div>' : '') + '</div></div></div>';
                }).join('');
            }
            SW.pagination(document.getElementById('notifPagination'), d.page, d.per_page, d.total, function (p) { logPage = p; loadLog(); });
        } catch (e) { el.innerHTML = SW.emptyState('bi-wifi-off', 'Unable to load log', e.message); }
    }

    document.addEventListener('sw:ready', function () {
        SW.qsa('form[data-section]').forEach(function (form) { form.addEventListener('submit', function (ev) { ev.preventDefault(); saveForm(form); }); });
        const te = document.getElementById('testEmail');
        if (te) te.addEventListener('click', function () { test('email', te); });
        const tt = document.getElementById('testTelegram');
        if (tt) tt.addEventListener('click', function () { test('telegram', tt); });
        const nr = document.getElementById('notifRefresh');
        if (nr) nr.addEventListener('click', loadLog);
        if (document.getElementById('notifLog')) { document.getElementById('notifLog').innerHTML = '<div class="p-3">' + '<div class="skeleton skeleton-line w-75">&nbsp;</div><div class="skeleton skeleton-line w-50">&nbsp;</div><div class="skeleton skeleton-line w-75">&nbsp;</div></div>'; loadLog(); }
    });
})();
