/* SiteWatch — Performance › Countries */
(function () {
    'use strict';

    let data = null;
    let search = '';
    let filter = '';
    let running = false;

    function tone(result) { return result ? (data.tones[result] || 'neutral') : 'none'; }

    function cellTitle(country, cell) {
        if (!cell) return country.name + ': not checked yet';
        let t = country.name + ': ' + (data.labels[cell.result] || cell.result);
        if (cell.response_ms !== null && (cell.result === 'reachable' || cell.result === 'slow')) t += ' · ' + SW.fmt.ms(cell.response_ms);
        else if (cell.status_code) t += ' · HTTP ' + cell.status_code;
        return t;
    }

    function renderHead() {
        const cols = data.countries.map(function (c) {
            return '<th scope="col" class="cc-col" title="' + SW.escape(c.name) + '"><abbr title="' + SW.escape(c.name) + '">' + SW.escape(c.code) + '</abbr></th>';
        }).join('');
        document.getElementById('ccHead').innerHTML = '<tr><th scope="col">Website</th>' + cols + '<th scope="col" class="hide-mobile">Checked</th>' +
            (data.canRun ? '<th scope="col" class="actions"><span class="visually-hidden">Actions</span></th>' : '') + '</tr>';
    }

    function renderLegend() {
        const order = ['reachable', 'slow', 'geo_blocked', 'blocked', 'unreachable', 'down', 'no_probe'];
        document.getElementById('ccLegend').innerHTML = order.map(function (k) {
            return '<span class="cc-legend-item"><span class="cc-dot tone-' + SW.escape(data.tones[k]) + '" aria-hidden="true"></span>' + SW.escape(data.labels[k]) + '</span>';
        }).join('') + '<span class="cc-legend-item"><span class="cc-dot tone-none" aria-hidden="true"></span>Not checked</span>';
    }

    function renderSummary() {
        const c = data.counts;
        const set = function (k, v) { const el = document.querySelector('#ccSummary [data-sum="' + k + '"]'); if (el) el.textContent = v; };
        set('everywhere', SW.fmt.num(c.everywhere));
        set('slow', SW.fmt.num(c.slow));
        set('problem', SW.fmt.num(c.problem));
        set('unchecked', SW.fmt.num(c.unchecked));
        set('interval', 'checked every ' + (data.interval === 1 ? 'hour' : data.interval + ' hours'));
        const problem = document.querySelector('#ccSummary [data-sum="problem"]');
        if (problem) problem.classList.toggle('tone-danger', c.problem > 0);
        const slow = document.querySelector('#ccSummary [data-sum="slow"]');
        if (slow) slow.classList.toggle('tone-warning', c.slow > 0);
        SW.qsa('#ccSummary [data-filter]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-filter') === filter)); });
    }

    function renderBody() {
        const body = document.getElementById('ccBody');
        const cols = data.countries.length + 2 + (data.canRun ? 1 : 0);
        const rows = data.rows.filter(function (r) {
            if (filter && r.worst !== filter) return false;
            return !search || (r.name + ' ' + r.domain + ' ' + (r.client_name || '')).toLowerCase().indexOf(search) !== -1;
        });
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="' + cols + '">' + SW.emptyState('bi-globe-europe-africa',
                data.rows.length ? 'No websites match this view.' : 'No websites yet.',
                data.rows.length ? 'Clear the search or pick another tile above.' : 'Add a website to check where it can be opened.') + '</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            const cells = data.countries.map(function (c) {
                const cell = r.cells[c.code];
                const label = cellTitle(c, cell);
                return '<td class="cc-col"><button type="button" class="cc-cell tone-' + tone(cell && cell.result) + (cell && cell.confirmed ? ' confirmed' : '') + '" data-site="' + r.id + '" data-country="' + SW.escape(c.code) + '" title="' + SW.escape(label) + '" aria-label="' + SW.escape(label) + '"></button></td>';
            }).join('');
            return '<tr data-row="' + r.id + '"><td>' + SW.siteCell(r) + '</td>' + cells +
                '<td class="hide-mobile fs-13 text-muted nowrap">' + (r.checked_at ? SW.timeAgoEl(r.checked_at) : 'Never') + '</td>' +
                (data.canRun ? '<td class="actions"><button type="button" class="btn-icon btn-sm" data-run="' + r.id + '" title="Check from every country now" aria-label="Check ' + SW.escape(r.name) + ' from every country now"><i class="bi bi-arrow-repeat"></i></button></td>' : '') +
                '</tr>';
        }).join('');
    }

    function render() { renderSummary(); renderBody(); }

    function openDetails(siteId, code) {
        const row = data.rows.find(function (r) { return r.id === siteId; });
        const country = data.countries.find(function (c) { return c.code === code; });
        if (!row || !country) return;
        const cell = row.cells[code];
        document.getElementById('ccPanelSite').textContent = row.name;
        document.getElementById('ccPanelTitle').textContent = country.name;
        let html;
        if (!cell) {
            html = SW.emptyState('bi-hourglass', 'Not checked yet', 'Use the refresh button on the row, or wait for the scheduled check.');
        } else {
            const facts = [];
            facts.push(['Result', '<span class="sw-pill tone-' + SW.escape(tone(cell.result)) + '">' + SW.escape(data.labels[cell.result] || cell.result) + '</span>' + (cell.confirmed ? ' <span class="fs-12 text-muted">confirmed on a second network</span>' : '')]);
            if (cell.status_code) facts.push(['HTTP status', '<span class="http-code ' + SW.fmt.httpClass(cell.status_code) + '">' + cell.status_code + '</span>']);
            if (cell.response_ms !== null) facts.push(['Response time', SW.responseTime(cell.response_ms)]);
            if (cell.error) facts.push(['Error', '<span class="mono">' + SW.escape(cell.error) + '</span>']);
            if (cell.network) facts.push(['Test network', SW.escape(cell.network) + (cell.probe_type ? ' <span class="fs-12 text-muted">(' + (cell.probe_type === 'home' ? 'home / office connection' : 'data centre') + ')</span>' : '')]);
            if (cell.city) facts.push(['Location', SW.escape(cell.city) + ', ' + SW.escape(country.name)]);
            facts.push(['Checked', SW.timeAgoEl(cell.checked_at)]);
            if (cell.changed_at && cell.changed_at !== cell.checked_at) facts.push(['Since', SW.escape(SW.fmt.date(cell.changed_at, true))]);
            facts.push(['Tested by', cell.provider === 'checkhost' ? 'check-host.net' : 'Globalping']);
            html = '<p class="fs-13 text-secondary mb-3">' + SW.escape(data.explain[cell.result] || '') + '</p>' +
                '<dl class="kv-list">' + facts.map(function (f) { return '<dt>' + f[0] + '</dt><dd>' + f[1] + '</dd>'; }).join('') + '</dl>';
        }
        html += '<div class="divider-top d-flex gap-2 flex-wrap">' +
            '<a class="btn btn-sm btn-light" href="' + SW.url('admin/website-details.php', { id: row.id }) + '"><i class="bi bi-box-arrow-up-right"></i>Open website</a>' +
            (data.canRun ? '<button type="button" class="btn btn-sm btn-light" data-run="' + row.id + '"><i class="bi bi-arrow-repeat"></i>Check again</button>' : '') + '</div>';
        document.getElementById('ccPanelBody').innerHTML = html;
        SW.panel.open('ccPanel');
    }

    async function runOne(id, quiet) {
        const res = await SW.api('api/countries/run.php', { method: 'POST', body: { website_id: id } });
        const row = data.rows.find(function (r) { return r.id === id; });
        if (row) {
            res.data.countries.forEach(function (c) { row.cells[c.code] = c.result ? c : null; });
            row.checked_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
        }
        if (!quiet) SW.toast(res.message || 'Checked.', 'success');
        return res;
    }

    async function reload() {
        const res = await SW.api('api/countries/list.php');
        data = res.data;
        render();
    }

    document.addEventListener('sw:ready', function () {
        const body = document.getElementById('ccBody');
        if (!body || !SW.page.countries) return;
        data = SW.page;
        renderHead();
        renderLegend();
        render();

        document.getElementById('ccSearch').addEventListener('input', SW.debounce(function () {
            search = this.value.trim().toLowerCase();
            renderBody();
        }, 150));
        SW.qsa('#ccSummary [data-filter]').forEach(function (b) {
            b.addEventListener('click', function () {
                const f = b.getAttribute('data-filter');
                filter = filter === f ? '' : f;
                render();
            });
        });

        // On document (not <main>, which outlives the page) so it is removed when the user leaves.
        document.addEventListener('click', async function (ev) {
            const cell = ev.target.closest('.cc-cell');
            if (cell) { openDetails(parseInt(cell.getAttribute('data-site'), 10), cell.getAttribute('data-country')); return; }
            const run = ev.target.closest('[data-run]');
            if (run && !running) {
                const id = parseInt(run.getAttribute('data-run'), 10);
                running = true;
                SW.setLoading(run, true);
                try {
                    await runOne(id);
                    SW.panel.close();
                    await reload();
                } catch (e) {
                    SW.toast(e.message, e.status === 429 ? 'warning' : 'danger');
                } finally {
                    running = false;
                    SW.setLoading(run, false);
                }
            }
        });

        const all = document.getElementById('ccRunAll');
        if (all) {
            all.addEventListener('click', async function () {
                if (running) return;
                const ok = await SW.confirm({
                    title: 'Check every website now?',
                    message: 'Each website is tested from ' + data.countries.length + ' countries, one after the other (about 10 seconds each). The free allowance is ' +
                        (data.hasToken ? '500' : '250') + ' tests an hour; anything left is checked by the scheduled job.',
                    confirmText: 'Start checking', danger: false, icon: 'bi-globe-europe-africa',
                });
                if (!ok) return;
                running = true;
                const ids = data.rows.filter(function (r) { return !r.paused; }).map(function (r) { return r.id; });
                const original = all.innerHTML;
                all.disabled = true;
                let done = 0;
                try {
                    for (let i = 0; i < ids.length; i++) {
                        all.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Checking ' + (i + 1) + ' of ' + ids.length + '…';
                        await runOne(ids[i], true);
                        done++;
                        renderBody();
                    }
                    SW.toast('Checked ' + done + ' websites from every country.', 'success');
                } catch (e) {
                    SW.toast((done ? 'Checked ' + done + ' websites. ' : '') + e.message, e.status === 429 ? 'warning' : 'danger', { delay: 9000 });
                } finally {
                    running = false;
                    all.disabled = false;
                    all.innerHTML = original;
                    reload().catch(function () {});
                }
            });
        }
    });
})();
