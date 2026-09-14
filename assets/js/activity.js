/* SiteWatch — activity log page */
(function () {
    'use strict';

    const state = { q: '', action: '', page: 1, perPage: 30 };
    const icons = { 'incident.opened': 'bi-x-octagon', 'incident.resolved': 'bi-check-circle', 'website.added': 'bi-plus-circle', 'website.edited': 'bi-pencil', 'website.deleted': 'bi-trash', 'website.imported': 'bi-upload', 'website.warning': 'bi-exclamation-triangle', 'monitoring.paused': 'bi-pause-circle', 'monitoring.resumed': 'bi-play-circle', 'ssl.warning': 'bi-shield-exclamation', 'ssl.renewed': 'bi-shield-check', 'settings.changed': 'bi-gear', 'profile.updated': 'bi-person', 'password.changed': 'bi-key', 'auth.login': 'bi-box-arrow-in-right', 'auth.logout': 'bi-box-arrow-right', 'notification.test': 'bi-bell', 'check.manual': 'bi-arrow-repeat', 'bulk.action': 'bi-check2-square' };

    async function load() {
        const list = document.getElementById('activityList');
        list.classList.add('is-refreshing');
        try {
            const res = await SW.api('api/activity/list.php', { query: { q: state.q, action: state.action, page: state.page, per_page: state.perPage } });
            const d = res.data;
            if (!d.rows.length) { list.innerHTML = SW.emptyState('bi-clock-history', state.q || state.action ? 'No activity matches these filters.' : 'No activity recorded yet.'); }
            else {
                list.innerHTML = d.rows.map(function (a) {
                    return '<div class="activity-item"><span class="activity-icon tone-' + SW.escape(a.tone) + '"><i class="bi ' + (icons[a.action] || 'bi-dot') + '"></i></span><div class="b">' +
                        '<div class="t">' + SW.escape(a.description) + (a.website_id ? ' <a class="fs-12" href="' + SW.url('admin/website-details.php', { id: a.website_id }) + '">open</a>' : '') + '</div>' +
                        '<div class="m">' + SW.pill(a.action_label, a.tone === 'muted' ? 'neutral' : a.tone) + ' ' + (a.user_name ? SW.escape(a.user_name) + ' · ' : '') + SW.escape(a.created_label) + ' · ' + SW.timeAgoEl(a.created_at) + (a.ip ? ' · ' + SW.escape(a.ip) : '') + '</div></div></div>';
                }).join('');
            }
            SW.pagination(document.getElementById('activityPagination'), d.page, d.per_page, d.total, function (p) { state.page = p; load(); });
        } catch (e) { list.innerHTML = SW.emptyState('bi-wifi-off', 'Unable to load activity', e.message); }
        finally { list.classList.remove('is-refreshing'); }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('activityPage')) return;
        const sel = document.getElementById('activityAction');
        Object.keys(SW.page.actions || {}).forEach(function (k) { const o = document.createElement('option'); o.value = k; o.textContent = SW.page.actions[k]; sel.appendChild(o); });
        sel.addEventListener('change', function () { state.action = sel.value; state.page = 1; load(); });
        document.getElementById('activitySearch').addEventListener('input', SW.debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
        document.getElementById('activityRefresh').addEventListener('click', load);
        document.getElementById('activityList').innerHTML = '<div class="p-3"><div class="skeleton skeleton-line w-75">&nbsp;</div><div class="skeleton skeleton-line w-50">&nbsp;</div><div class="skeleton skeleton-line w-75">&nbsp;</div></div>';
        load();
        SW.poll(function () { if (state.page === 1) return load(); }, Math.max(30000, (SW.config.refresh || 30) * 1000));
    });
})();
