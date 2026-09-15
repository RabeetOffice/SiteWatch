/* SiteWatch — activity log page */
(function () {
    'use strict';

    const state = { q: '', action: '', page: 1, perPage: 30 };

    function eventRow(a) {
        const site = a.website_id
            ? '<a href="' + SW.url('admin/website-details.php', { id: a.website_id }) + '">' + SW.escape(a.website_name || a.domain || 'Website') + '</a>'
            : '<span class="text-faint">—</span>';
        const meta = [];
        if (a.ip) meta.push(['IP address', SW.escape(a.ip)]);
        if (a.context) {
            Object.keys(a.context).forEach(function (k) {
                const v = a.context[k];
                if (v === null || v === undefined || typeof v === 'object') return;
                meta.push([SW.escape(k.replace(/_/g, ' ')), SW.escape(String(v))]);
            });
        }
        const details = meta.length
            ? '<details class="detail-toggle"><summary>Technical details</summary><div class="detail-body"><dl class="kv-list fs-12 mb-0">' +
              meta.map(function (m) { return '<dt>' + m[0] + '</dt><dd>' + m[1] + '</dd>'; }).join('') + '</dl></div></details>'
            : '';

        return '<div class="event-row">' +
            '<div class="event-desc"><div class="d">' + SW.escape(a.description) + '</div>' +
            '<div class="k">' + SW.escape(a.action_label) + '</div>' + details + '</div>' +
            '<div class="event-site">' + site + '</div>' +
            '<div class="event-actor">' + (a.user_name ? SW.escape(a.user_name) : '<span class="text-faint">System</span>') + '</div>' +
            '<div class="event-time" title="' + SW.escape(a.created_label) + '">' + SW.timeAgoEl(a.created_at) + '</div>' +
            '</div>';
    }

    async function load(silent) {
        const list = document.getElementById('activityList');
        if (silent && (list.contains(document.activeElement) || list.querySelector('details[open]'))) return;
        if (!silent) list.classList.add('is-refreshing');
        try {
            const res = await SW.api('api/activity/list.php', {
                query: { q: state.q, action: state.action, page: state.page, per_page: state.perPage },
            });
            const d = res.data;
            const summary = document.getElementById('activitySummary');
            if (summary) summary.textContent = SW.fmt.num(d.total) + (d.total === 1 ? ' event' : ' events');
            if (!d.rows.length) {
                list.innerHTML = SW.emptyState('bi-clock-history',
                    state.q || state.action ? 'No activity matches these filters.' : 'No activity recorded yet.',
                    state.q || state.action ? 'Try a different search term or event type.' : 'Administrative and monitoring events will appear here.');
            } else {
                list.innerHTML = d.rows.map(eventRow).join('');
            }
            SW.pagination(document.getElementById('activityPagination'), d.page, d.per_page, d.total, function (p) { state.page = p; load(); });
        } catch (e) {
            list.innerHTML = SW.emptyState('bi-wifi-off', 'Unable to load activity', e.message);
        } finally {
            list.classList.remove('is-refreshing');
        }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('activityPage')) return;
        const sel = document.getElementById('activityAction');
        Object.keys(SW.page.actions || {}).forEach(function (k) {
            const o = document.createElement('option');
            o.value = k;
            o.textContent = SW.page.actions[k];
            sel.appendChild(o);
        });
        sel.addEventListener('change', function () { state.action = sel.value; state.page = 1; load(); });
        document.getElementById('activitySearch').addEventListener('input', SW.debounce(function () {
            state.q = this.value.trim();
            state.page = 1;
            load();
        }, 300));
        document.getElementById('activityRefresh').addEventListener('click', function () { load(); });
        document.getElementById('activityList').innerHTML =
            '<div class="p-3"><div class="skeleton skeleton-line w-75">&nbsp;</div><div class="skeleton skeleton-line w-50">&nbsp;</div><div class="skeleton skeleton-line w-75">&nbsp;</div></div>';
        load();
        SW.poll(function () { if (state.page === 1) return load(true); }, Math.max(30000, (SW.config.refresh || 30) * 1000));
    });
})();
