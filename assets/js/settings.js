/* SiteWatch — settings & notifications pages */
(function () {
    'use strict';

    function serialize(form) {
        const body = {};
        SW.qsa('input, select, textarea', form).forEach(function (el) {
            if (!el.name) return;
            // name[] checkboxes become a list of the checked values.
            if (el.name.slice(-2) === '[]') {
                const key = el.name.slice(0, -2);
                body[key] = body[key] || [];
                if (el.checked) body[key].push(el.value);
                return;
            }
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
            setDirty(form, false);
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

    const CHANNEL_ICONS = {
        email: 'bi-envelope',
        telegram: 'bi-telegram',
        whatsapp: 'bi-whatsapp',
        discord: 'bi-discord',
        push: 'bi-window-desktop'
    };

    // The WhatsApp card carries the credentials for both providers; only the selected one is shown.
    function syncWhatsappProvider() {
        const select = document.getElementById('whatsapp_provider');
        if (!select) return;
        SW.qsa('[data-whatsapp-provider]').forEach(function (block) {
            block.hidden = block.getAttribute('data-whatsapp-provider') !== select.value;
        });
    }

    let logPage = 1;
    async function loadLog() {
        const el = document.getElementById('notifLog');
        if (!el) return;
        try {
            const res = await SW.api('api/notifications/list.php', { query: { page: logPage, per_page: 10 } });
            const d = res.data;
            if (!d.rows.length) {
                el.innerHTML = SW.emptyState('bi-bell-slash', 'Nothing sent yet.', 'Alerts appear here once an incident is confirmed or a test is sent.');
            } else {
                el.innerHTML = d.rows.map(function (n) {
                    const tone = n.status === 'sent' ? 'success' : (n.status === 'failed' ? 'danger' : 'neutral');
                    const icon = CHANNEL_ICONS[n.channel] || 'bi-bell-slash';
                    return '<div class="activity-item"><span class="activity-icon tone-' + (tone === 'neutral' ? 'muted' : tone) + '" aria-hidden="true"><i class="bi ' + icon + '"></i></span>' +
                        '<div class="b">' +
                        '<div class="t d-flex align-items-center gap-2 flex-wrap"><b>' + SW.escape(n.event_label) + '</b>' +
                        (n.website_name ? '<a href="' + SW.url('admin/website-details.php', { id: n.website_id }) + '">' + SW.escape(n.website_name) + '</a>' : '') +
                        SW.pill(n.status, tone) + '</div>' +
                        '<div class="m"><span>' + SW.escape(n.channel_label) + '</span>' +
                        (n.recipient ? '<span class="break-anywhere">' + SW.escape(n.recipient) + '</span>' : '') +
                        '<span>' + SW.timeAgoEl(n.created_at) + '</span></div>' +
                        (n.error_message ? '<details class="detail-toggle"><summary>Why it failed</summary><div class="detail-body break-anywhere">' + SW.escape(n.error_message) + '</div></details>' : '') +
                        '</div></div>';
                }).join('');
            }
            SW.pagination(document.getElementById('notifPagination'), d.page, d.per_page, d.total, function (p) { logPage = p; loadLog(); });
        } catch (e) {
            el.innerHTML = SW.emptyState('bi-wifi-off', 'Unable to load the delivery history', e.message);
        }
    }

    /** The save bar of a form sticks to the bottom of the window while it has unsaved changes. */
    function setDirty(form, dirty) {
        const bar = form.querySelector('.sw-form-actions');
        if (!bar) return;
        bar.classList.toggle('is-dirty', dirty);
        let note = bar.querySelector('[data-dirty-note]');
        if (dirty && !note) {
            note = document.createElement('span');
            note.className = 'fs-13 text-warning me-auto';
            note.setAttribute('data-dirty-note', '');
            note.innerHTML = '<i class="bi bi-dot" aria-hidden="true"></i>Unsaved changes';
            bar.insertBefore(note, bar.firstChild);
        } else if (!dirty && note) {
            note.remove();
        }
    }

    /** Settings › Monitoring: how many tests the chosen countries use per day. */
    function countryEstimate() {
        const el = document.querySelector('[data-country-estimate]');
        if (!el) return;
        const count = SW.qsa('input[name="countries[]"]:checked').length;
        const sites = parseInt(el.getAttribute('data-websites'), 10) || 0;
        const hours = parseInt((document.getElementById('country_check_interval_hours') || {}).value, 10) || 24;
        const perDay = Math.round(count * sites * (24 / hours));
        el.textContent = count + ' of 15 countries chosen · about ' + SW.fmt.num(perDay) + ' tests a day for ' + sites + ' websites' +
            (count > 15 ? ' — choose at most 15.' : '.');
        el.classList.toggle('text-danger', count > 15 || count === 0);
    }

    document.addEventListener('sw:ready', function () {
        SW.qsa('form[data-section]').forEach(function (form) {
            form.addEventListener('submit', function (ev) { ev.preventDefault(); saveForm(form); });
            form.addEventListener('input', function () { setDirty(form, true); });
            form.addEventListener('change', function () { setDirty(form, true); countryEstimate(); });
        });
        countryEstimate();
        [['testEmail', 'email'], ['testTelegram', 'telegram'], ['testWhatsapp', 'whatsapp'], ['testDiscord', 'discord']].forEach(function (pair) {
            const btn = document.getElementById(pair[0]);
            if (btn) btn.addEventListener('click', function () { test(pair[1], btn); });
        });
        const wp = document.getElementById('whatsapp_provider');
        if (wp) { wp.addEventListener('change', syncWhatsappProvider); syncWhatsappProvider(); }
        const nr = document.getElementById('notifRefresh');
        if (nr) nr.addEventListener('click', loadLog);
        if (document.getElementById('notifLog')) { document.getElementById('notifLog').innerHTML = '<div class="p-3">' + '<div class="skeleton skeleton-line w-75">&nbsp;</div><div class="skeleton skeleton-line w-50">&nbsp;</div><div class="skeleton skeleton-line w-75">&nbsp;</div></div>'; loadLog(); }
    });
})();
