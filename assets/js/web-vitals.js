/* SiteWatch — Core Web Vitals page */
(function () {
    'use strict';

    const COLUMNS = 9;
    const RATING_TONE = { good: 'success', 'needs-improvement': 'warning', poor: 'danger' };

    let data = null;
    let strategy = SW.storage.get('sw-cwv-strategy', 'mobile');
    let filter = '';

    /** A metric cell, coloured by Google's good / needs-improvement / poor bands. */
    function metric(cell) {
        if (!cell || cell.value === null || cell.value === undefined) {
            return '<span class="text-faint">—</span>';
        }
        const tone = RATING_TONE[cell.rating];
        return '<span class="cwv-metric' + (tone ? ' text-' + tone : '') + '">' + SW.escape(cell.display) + '</span>';
    }

    function score(run) {
        if (!run || run.score === null) return '<span class="text-faint">—</span>';
        const tone = RATING_TONE[run.score_rating] || 'neutral';
        return '<span class="sw-pill tone-' + tone + '">' + SW.escape(String(run.score)) + '</span>';
    }

    function renderSummary() {
        const set = function (k, v) {
            const el = document.querySelector('[data-sum="' + k + '"]');
            if (el) el.textContent = v;
        };
        set('average', data.average === null ? '—' : String(data.average));
        set('measured', data.measured + ' of ' + data.total + ' websites measured');
        set('good', String(data.counts.good || 0));
        set('needs-improvement', String(data.counts['needs-improvement'] || 0));
        set('poor', String(data.counts.poor || 0));
    }

    function renderTable() {
        const body = document.getElementById('cwvBody');
        const rows = data.rows.filter(function (r) {
            return !filter || (r.name + ' ' + r.url + ' ' + r.client_name).toLowerCase().indexOf(filter) !== -1;
        });
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + SW.emptyState(
                'bi-activity',
                data.rows.length ? 'No websites match the filter.' : 'Nothing measured yet.',
                data.rows.length
                    ? 'Clear the filter to see every website.'
                    : (data.enabled
                        ? 'Results appear after cron/vitals-check.php runs, or use "Measure now" on a website.'
                        : 'Enable Core Web Vitals under Monitoring Settings to start collecting results.')
            ) + '</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            const run = r.run;
            const lab = run ? run.lab : null;
            const field = run ? run.field : null;
            // INP only exists as real-user data; TBT is shown separately as the lab stand-in.
            const inp = field && field.inp && field.inp.value !== null
                ? metric(field.inp)
                : '<span class="text-faint" title="Interaction to Next Paint needs real-user data, which Chrome only reports once a site has enough traffic.">—</span>';

            return '<tr>' +
                '<td><div class="site-text"><a class="site-name" href="' + SW.url('admin/website-details.php', { id: r.id }) + '">' + SW.escape(r.name) + '</a>' +
                '<span class="site-domain">' + SW.escape(r.url) + '</span></div></td>' +
                '<td class="hide-mobile">' + (r.client_name ? SW.escape(r.client_name) : '<span class="text-faint">—</span>') + '</td>' +
                '<td class="num">' + score(run) + '</td>' +
                '<td class="num">' + metric(lab && lab.lcp) + '</td>' +
                '<td class="num">' + metric(lab && lab.cls) + '</td>' +
                '<td class="num">' + inp + '</td>' +
                '<td class="num hide-mobile">' + metric(lab && lab.tbt) + '</td>' +
                '<td class="num">' + SW.responseTime(r.last_ttfb) + '</td>' +
                '<td class="hide-mobile fs-13">' + (run ? SW.timeAgoEl(run.fetched_at) : '<span class="text-faint">Never</span>') + '</td>' +
                '</tr>';
        }).join('');
    }

    async function load() {
        const body = document.getElementById('cwvBody');
        if (body) body.innerHTML = SW.skeletonRows(COLUMNS, 6);
        try {
            const res = await SW.api('api/performance/vitals.php', { query: { strategy: strategy } });
            data = res.data;
            renderSummary();
            renderTable();
        } catch (e) {
            if (body) body.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + SW.emptyState('bi-wifi-off', 'Unable to load Core Web Vitals', e.message) + '</td></tr>';
        }
    }

    document.addEventListener('sw:ready', function () {
        SW.qsa('#cwvStrategy button').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-strategy') === strategy);
            btn.addEventListener('click', function () {
                strategy = btn.getAttribute('data-strategy');
                SW.storage.set('sw-cwv-strategy', strategy);
                SW.qsa('#cwvStrategy button').forEach(function (b) { b.classList.toggle('active', b === btn); });
                load();
            });
        });

        const search = document.getElementById('cwvSearch');
        if (search) {
            search.addEventListener('input', function () {
                filter = search.value.trim().toLowerCase();
                if (data) renderTable();
            });
        }

        load();
    });
})();
