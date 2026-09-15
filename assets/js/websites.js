/* SiteWatch — reusable website inventory table (dashboard + websites page) */
(function () {
    'use strict';

    const FILTERS = [
        { key: 'all', label: 'All websites' },
        { key: 'online', label: 'Online' },
        { key: 'down', label: 'Down' },
        { key: 'critical', label: 'Critical error' },
        { key: 'warning', label: 'Warning' },
        { key: 'slow', label: 'Slow' },
        { key: 'ssl_expiring', label: 'SSL expiring' },
        { key: 'paused', label: 'Paused' },
    ];
    const SORTS = [
        { key: 'status', label: 'Severity' },
        { key: 'response', label: 'Response time' },
        { key: 'uptime', label: 'Uptime' },
        { key: 'last_checked', label: 'Last checked' },
        { key: 'client', label: 'Client' },
        { key: 'name', label: 'Name' },
        { key: 'newest', label: 'Newest first' },
        { key: 'oldest', label: 'Oldest first' },
    ];
    const COLUMNS = 9;

    function filterLabel(key) {
        const f = FILTERS.find(function (x) { return x.key === key; });
        return f ? f.label : key;
    }

    function WebsiteTable(container, options) {
        if (!container) return;
        this.el = container;
        this.opts = Object.assign({
            perPage: 25, bulk: true, search: true, filters: true, pagination: true, storageKey: 'sw-websites-table',
            title: 'Websites', subtitle: '', compact: false, footerLink: null, showClientFilter: true,
        }, options || {});
        const saved = SW.storage.get(this.opts.storageKey, {});
        this.state = {
            q: saved.q || '', filter: saved.filter || 'all', sort: saved.sort || 'status',
            dir: saved.dir || '', client: saved.client || '', page: 1, perPage: this.opts.perPage,
        };
        this.rows = [];
        this.selected = new Set();
        this.busy = new Set();
        this.total = 0;
        this.counts = {};
        this.clients = [];
        this.abort = null;
        this.render();
        this.load();
    }

    WebsiteTable.prototype.persist = function () {
        SW.storage.set(this.opts.storageKey, {
            q: this.state.q, filter: this.state.filter, sort: this.state.sort, dir: this.state.dir, client: this.state.client,
        });
    };

    WebsiteTable.prototype.render = function () {
        const o = this.opts;
        let html = '<div class="sw-card-header"><div><h3>' + SW.escape(o.title) + '</h3>' +
            (o.subtitle ? '<p class="sub">' + SW.escape(o.subtitle) + '</p>' : '') + '</div>' +
            '<div class="d-flex gap-2 align-items-center">' +
            (o.exportUrl ? '<a class="btn btn-sm btn-light" href="' + SW.escape(o.exportUrl) + '" data-export><i class="bi bi-download"></i> Export CSV</a>' : '') +
            '<button type="button" class="btn-icon btn-sm" data-action="refresh" aria-label="Refresh website list" data-bs-toggle="tooltip" title="Refresh now"><i class="bi bi-arrow-clockwise"></i></button>' +
            '</div></div>';

        html += '<div class="sw-toolbar">';
        if (o.search) {
            html += '<div class="search"><i class="bi bi-search" aria-hidden="true"></i>' +
                '<input type="search" class="form-control form-control-sm" placeholder="Search name, domain or client…" aria-label="Search websites" data-search value="' + SW.escape(this.state.q) + '"></div>';
        }
        html += '<div class="toolbar-group">';
        if (o.filters) {
            html += '<select class="form-select form-select-sm" data-filter-select aria-label="Filter by status">' +
                FILTERS.map(function (f) { return '<option value="' + f.key + '">' + SW.escape(f.label) + '</option>'; }).join('') + '</select>';
        }
        if (o.showClientFilter) {
            html += '<select class="form-select form-select-sm" data-client aria-label="Filter by client"><option value="">All clients</option></select>';
        }
        html += '<select class="form-select form-select-sm" data-sort aria-label="Sort websites">' +
            SORTS.map(function (s) { return '<option value="' + s.key + '">Sort: ' + SW.escape(s.label) + '</option>'; }).join('') + '</select>';
        html += '</div></div>';

        html += '<div class="filter-summary" data-filter-summary hidden></div>';

        if (o.bulk) {
            html += '<div class="bulk-bar" data-bulk role="region" aria-label="Bulk actions">' +
                '<span class="count" data-bulk-count>0 selected</span>' +
                '<button type="button" class="btn btn-sm btn-light" data-bulk-action="check"><i class="bi bi-arrow-repeat"></i> Check now</button>' +
                '<button type="button" class="btn btn-sm btn-light" data-bulk-action="pause"><i class="bi bi-pause"></i> Pause</button>' +
                '<button type="button" class="btn btn-sm btn-light" data-bulk-action="resume"><i class="bi bi-play"></i> Resume</button>' +
                '<select class="form-select form-select-sm" data-bulk-interval aria-label="Change monitoring interval for selected websites" style="width:auto">' +
                '<option value="">Change interval…</option><option value="1">1 minute</option><option value="2">2 minutes</option><option value="5">5 minutes</option>' +
                '<option value="10">10 minutes</option><option value="15">15 minutes</option><option value="30">30 minutes</option></select>' +
                '<button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-bulk-action="delete"><i class="bi bi-trash"></i> Delete</button>' +
                '<button type="button" class="btn btn-sm btn-ghost" data-bulk-action="clear">Clear selection</button></div>';
        }

        html += '<div class="sw-table-wrap"><table class="sw-table' + (o.compact ? ' compact' : '') + '"><thead><tr>' +
            (o.bulk ? '<th class="w-min"><input type="checkbox" class="form-check-input" data-select-all aria-label="Select all websites on this page"></th>' : '') +
            '<th class="sortable" data-sort-col="name" scope="col">Website <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>' +
            '<th class="sortable hide-mobile" data-sort-col="client" scope="col">Client <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>' +
            '<th class="sortable" data-sort-col="status" scope="col">Status <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>' +
            '<th class="sortable num" data-sort-col="response" scope="col">Response <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>' +
            '<th class="hide-mobile" scope="col">SSL</th>' +
            '<th class="sortable num" data-sort-col="uptime" scope="col">Uptime 30d <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>' +
            '<th class="sortable" data-sort-col="last_checked" scope="col">Last checked <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>' +
            '<th class="actions" scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>' +
            '<tbody data-body>' + SW.skeletonRows(o.bulk ? COLUMNS : COLUMNS - 1, 6) + '</tbody></table></div>';

        if (o.pagination) html += '<div class="sw-pagination" data-pagination></div>';
        if (o.footerLink) html += '<div class="sw-card-footer justify-content-center"><a href="' + SW.escape(o.footerLink.href) + '">' + SW.escape(o.footerLink.label) + ' <i class="bi bi-arrow-right"></i></a></div>';

        this.el.innerHTML = html;
        this.bind();
        this.syncControls();
    };

    WebsiteTable.prototype.bind = function () {
        const self = this, el = this.el;

        const search = el.querySelector('[data-search]');
        if (search) {
            search.addEventListener('input', SW.debounce(function () {
                self.state.q = search.value.trim();
                self.state.page = 1;
                self.persist();
                self.load();
            }, 300));
        }

        const filterSelect = el.querySelector('[data-filter-select]');
        if (filterSelect) {
            filterSelect.addEventListener('change', function () {
                self.state.filter = filterSelect.value;
                self.state.page = 1;
                self.persist();
                self.load();
            });
        }

        const sort = el.querySelector('[data-sort]');
        if (sort) {
            sort.addEventListener('change', function () {
                self.state.sort = sort.value;
                self.state.dir = '';
                self.state.page = 1;
                self.persist();
                self.syncSortUi();
                self.load();
            });
        }

        const client = el.querySelector('[data-client]');
        if (client) {
            client.addEventListener('change', function () {
                self.state.client = client.value;
                self.state.page = 1;
                self.persist();
                self.load();
            });
        }

        SW.qsa('[data-sort-col]', el).forEach(function (th) {
            th.tabIndex = 0;
            th.setAttribute('role', 'columnheader');
            th.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); th.click(); }
            });
            th.addEventListener('click', function () {
                const col = th.getAttribute('data-sort-col');
                if (self.state.sort === col) {
                    self.state.dir = self.state.dir === 'asc' ? 'desc' : (self.state.dir === 'desc' ? '' : 'asc');
                } else {
                    self.state.sort = col;
                    self.state.dir = '';
                }
                if (sort) sort.value = self.state.sort;
                self.state.page = 1;
                self.persist();
                self.syncSortUi();
                self.load();
            });
        });

        const refresh = el.querySelector('[data-action="refresh"]');
        if (refresh) refresh.addEventListener('click', function () { self.load(); });

        const selectAll = el.querySelector('[data-select-all]');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                self.rows.forEach(function (r) {
                    if (selectAll.checked) { self.selected.add(r.id); } else { self.selected.delete(r.id); }
                });
                SW.qsa('[data-select]', el).forEach(function (cb) { cb.checked = selectAll.checked; });
                self.syncSelection();
            });
        }

        SW.qsa('[data-bulk-action]', el).forEach(function (b) {
            b.addEventListener('click', function () { self.bulk(b.getAttribute('data-bulk-action')); });
        });
        const bulkInterval = el.querySelector('[data-bulk-interval]');
        if (bulkInterval) {
            bulkInterval.addEventListener('change', function () {
                if (bulkInterval.value) { self.bulk('interval', parseInt(bulkInterval.value, 10)); bulkInterval.value = ''; }
            });
        }

        el.addEventListener('click', function (ev) {
            const actionEl = ev.target.closest('[data-row-action]');
            if (actionEl) {
                ev.preventDefault();
                self.rowAction(actionEl.getAttribute('data-row-action'), parseInt(actionEl.getAttribute('data-id'), 10));
                return;
            }
            const removeFilter = ev.target.closest('[data-remove-filter]');
            if (removeFilter) {
                ev.preventDefault();
                self.clearFilter(removeFilter.getAttribute('data-remove-filter'));
                return;
            }
            const cb = ev.target.closest('[data-select]');
            if (cb) {
                const id = parseInt(cb.getAttribute('data-select'), 10);
                if (cb.checked) { self.selected.add(id); } else { self.selected.delete(id); }
                self.syncSelection();
            }
        });

        SW.tooltips(el);
    };

    /** Reflect the current state in the toolbar controls (used after external changes). */
    WebsiteTable.prototype.syncControls = function () {
        const el = this.el;
        const search = el.querySelector('[data-search]');
        if (search) search.value = this.state.q;
        const filterSelect = el.querySelector('[data-filter-select]');
        if (filterSelect) filterSelect.value = this.state.filter;
        const sort = el.querySelector('[data-sort]');
        if (sort) sort.value = this.state.sort;
        const client = el.querySelector('[data-client]');
        if (client) client.value = this.clients.indexOf(this.state.client) !== -1 ? this.state.client : '';
        this.syncSortUi();
        this.renderFilterSummary();
    };

    WebsiteTable.prototype.syncSortUi = function () {
        const self = this;
        SW.qsa('[data-sort-col]', this.el).forEach(function (th) {
            const active = th.getAttribute('data-sort-col') === self.state.sort;
            th.classList.toggle('sorted', active);
            const descending = self.state.dir === 'desc' || (!self.state.dir && ['response', 'last_checked', 'newest'].indexOf(self.state.sort) !== -1);
            th.setAttribute('aria-sort', active ? (descending ? 'descending' : 'ascending') : 'none');
            const icon = th.querySelector('i');
            if (icon) {
                icon.className = 'bi ' + (active
                    ? (self.state.dir === 'asc' ? 'bi-sort-up' : self.state.dir === 'desc' ? 'bi-sort-down' : 'bi-arrow-down-up')
                    : 'bi-arrow-down-up');
            }
        });
    };

    /** Applied filters are always visible and individually removable. */
    WebsiteTable.prototype.renderFilterSummary = function () {
        const wrap = this.el.querySelector('[data-filter-summary]');
        if (!wrap) return;
        const tags = [];
        if (this.state.filter && this.state.filter !== 'all') {
            const count = this.counts[this.state.filter];
            tags.push({ key: 'filter', label: 'Status', value: filterLabel(this.state.filter) + (count !== undefined ? ' (' + count + ')' : '') });
        }
        if (this.state.client) tags.push({ key: 'client', label: 'Client', value: this.state.client });
        if (this.state.q) tags.push({ key: 'q', label: 'Search', value: this.state.q });
        if (!tags.length) { wrap.hidden = true; wrap.innerHTML = ''; return; }
        wrap.hidden = false;
        wrap.innerHTML = '<span>Filtered by</span>' + tags.map(function (t) {
            return '<span class="filter-tag">' + SW.escape(t.label) + ': <b>' + SW.escape(t.value) + '</b>' +
                '<button type="button" data-remove-filter="' + t.key + '" aria-label="Remove ' + SW.escape(t.label) + ' filter"><i class="bi bi-x-lg" aria-hidden="true"></i></button></span>';
        }).join('') +
            '<button type="button" class="btn btn-sm btn-ghost" data-remove-filter="all">Clear all</button>' +
            '<span class="ms-auto">' + SW.fmt.num(this.total) + ' matching</span>';
    };

    WebsiteTable.prototype.clearFilter = function (key) {
        if (key === 'all') { this.state.filter = 'all'; this.state.client = ''; this.state.q = ''; }
        if (key === 'filter') this.state.filter = 'all';
        if (key === 'client') this.state.client = '';
        if (key === 'q') this.state.q = '';
        this.state.page = 1;
        this.persist();
        this.syncControls();
        this.load();
    };

    WebsiteTable.prototype.renderClients = function () {
        const sel = this.el.querySelector('[data-client]');
        if (!sel) return;
        const current = this.state.client;
        sel.innerHTML = '<option value="">All clients</option>' + this.clients.map(function (c) {
            return '<option value="' + SW.escape(c) + '">' + SW.escape(c) + '</option>';
        }).join('');
        sel.value = this.clients.indexOf(current) !== -1 ? current : '';
    };

    /** Status option labels carry their counts so the compact filter stays informative. */
    WebsiteTable.prototype.renderFilterCounts = function () {
        const sel = this.el.querySelector('[data-filter-select]');
        if (!sel) return;
        const counts = this.counts;
        SW.qsa('option', sel).forEach(function (opt) {
            const count = counts[opt.value];
            opt.textContent = filterLabel(opt.value) + (count !== undefined ? ' (' + count + ')' : '');
        });
        sel.value = this.state.filter;
    };

    WebsiteTable.prototype.load = async function (silent) {
        const body = this.el.querySelector('[data-body]');
        // A background refresh must never close an open menu or steal focus.
        if (silent && body && (body.contains(document.activeElement) || this.el.querySelector('.dropdown-menu.show'))) return;
        if (!silent && body) body.classList.add('is-refreshing');
        if (this.abort) this.abort.abort();
        this.abort = new AbortController();
        try {
            const res = await SW.api('api/websites/list.php', {
                query: {
                    q: this.state.q, filter: this.state.filter, sort: this.state.sort, dir: this.state.dir,
                    client: this.state.client, page: this.state.page, per_page: this.state.perPage,
                },
                signal: this.abort.signal,
            });
            this.rows = res.data.rows;
            this.total = res.data.total;
            this.counts = res.data.counts || {};
            this.clients = res.data.clients || [];
            if (res.data.page !== this.state.page) this.state.page = res.data.page;
            this.renderRows();
            this.renderFilterCounts();
            this.renderClients();
            this.renderFilterSummary();
            this.renderPagination();
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            if (body) body.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + SW.emptyState('bi-wifi-off', 'Unable to load websites', e.message) + '</td></tr>';
        } finally {
            if (body) body.classList.remove('is-refreshing');
        }
    };

    WebsiteTable.prototype.refresh = function (silent) { return this.load(silent !== false); };

    WebsiteTable.prototype.renderPagination = function () {
        const self = this, pag = this.el.querySelector('[data-pagination]');
        if (!pag) return;
        SW.pagination(pag, this.state.page, this.state.perPage, this.total, function (p) { self.state.page = p; self.load(); });
    };

    /** Routine values stay quiet; exceptions get the emphasis. */
    function sslCell(ssl) {
        if (!ssl.applicable) return '<span class="text-faint fs-13">' + SW.escape(ssl.label) + '</span>';
        if (ssl.tone === 'success') {
            const title = ssl.expires_at ? 'Expires ' + ssl.expires_label + (ssl.issuer ? ' · ' + ssl.issuer : '') : 'Certificate valid';
            return '<span class="fs-13 text-muted" data-bs-toggle="tooltip" title="' + SW.escape(title) + '"><i class="bi bi-shield-check" aria-hidden="true"></i> ' + SW.escape(ssl.label) + '</span>';
        }
        const tip = ssl.valid === false ? (ssl.error || 'Invalid certificate')
            : (ssl.expires_at ? 'Expires ' + ssl.expires_label + (ssl.issuer ? ' · ' + ssl.issuer : '') : 'Certificate not inspected yet');
        return '<span class="sw-pill tone-' + SW.escape(ssl.tone) + '" data-bs-toggle="tooltip" title="' + SW.escape(tip) + '">' +
            '<i class="bi ' + (ssl.tone === 'neutral' ? 'bi-shield' : 'bi-shield-exclamation') + '" aria-hidden="true"></i>' + SW.escape(ssl.label) + '</span>';
    }

    WebsiteTable.prototype.rowHtml = function (w) {
        const o = this.opts;
        const busy = this.busy.has(w.id);
        const selected = this.selected.has(w.id);

        let statusHtml = SW.badge(w.status, w.status_label, w.severity);
        if (w.status === 'SUSPECTED_DOWN') {
            statusHtml += '<div class="fs-12 text-muted mt-1">' + w.failure_count + ' of ' + w.failure_threshold + ' failures</div>';
        } else if (w.recovering) {
            statusHtml += '<div class="fs-12 text-muted mt-1">recovering ' + w.success_count + ' of ' + w.recovery_threshold + '</div>';
        } else if (w.last_error_message && w.severity !== 'ok') {
            statusHtml += '<div class="fs-12 text-muted mt-1 truncate" style="max-width:150px" title="' + SW.escape(w.last_error_message) + '">' + SW.escape(w.last_error_message) + '</div>';
        }

        const menu = '<div class="dropdown">' +
            '<button type="button" class="btn-icon btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for ' + SW.escape(w.name) + '"><i class="bi bi-three-dots" aria-hidden="true"></i></button>' +
            '<ul class="dropdown-menu dropdown-menu-end">' +
            '<li><a class="dropdown-item" href="' + SW.escape(w.urls.details) + '"><i class="bi bi-eye"></i> View details</a></li>' +
            '<li><a class="dropdown-item" href="' + SW.escape(w.urls.edit) + '"><i class="bi bi-pencil"></i> Edit</a></li>' +
            (w.monitoring_enabled
                ? '<li><a class="dropdown-item" href="#" data-row-action="pause" data-id="' + w.id + '"><i class="bi bi-pause-circle"></i> Pause monitoring</a></li>'
                : '<li><a class="dropdown-item" href="#" data-row-action="resume" data-id="' + w.id + '"><i class="bi bi-play-circle"></i> Resume monitoring</a></li>') +
            '<li><a class="dropdown-item" href="' + SW.escape(w.url) + '" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Open website</a></li>' +
            '<li><hr class="dropdown-divider"></li>' +
            '<li><a class="dropdown-item text-danger" href="#" data-row-action="delete" data-id="' + w.id + '"><i class="bi bi-trash"></i> Delete</a></li></ul></div>';

        return '<tr data-row="' + w.id + '"' + (selected ? ' class="selected"' : '') + '>' +
            (o.bulk ? '<td class="w-min"><input type="checkbox" class="form-check-input" data-select="' + w.id + '"' + (selected ? ' checked' : '') + ' aria-label="Select ' + SW.escape(w.name) + '"></td>' : '') +
            '<td><div class="site-cell">' + SW.favicon(w.favicon_url, w.domain) +
            '<div class="site-text"><a class="site-name" href="' + SW.escape(w.urls.details) + '" title="' + SW.escape(w.name) + '">' + SW.escape(w.name) + '</a>' +
            '<a class="site-domain" href="' + SW.escape(w.url) + '" target="_blank" rel="noopener noreferrer" title="' + SW.escape(w.url) + '">' + SW.escape(w.domain) +
            ' <i class="bi bi-box-arrow-up-right" style="font-size:10px" aria-hidden="true"></i></a></div></div></td>' +
            '<td class="hide-mobile">' + (w.client_name ? '<span class="client-name" title="' + SW.escape(w.client_name) + '">' + SW.escape(w.client_name) + '</span>' : '<span class="text-faint">—</span>') + '</td>' +
            '<td>' + statusHtml + '</td>' +
            '<td class="num">' + (busy
                ? '<span class="spinner-border spinner-border-sm text-muted" role="status" aria-label="Checking"></span>'
                : SW.responseTime(w.last_response_time) + (w.last_http_status ? '<div class="fs-12 text-muted">HTTP ' + SW.httpCode(w.last_http_status) + '</div>' : '')) + '</td>' +
            '<td class="hide-mobile">' + sslCell(w.ssl) + '</td>' +
            '<td class="num"><span class="fw-600">' + SW.escape(w.uptime_30d_label) + '</span></td>' +
            '<td class="nowrap fs-13">' + (w.last_checked_at ? SW.timeAgoEl(w.last_checked_at) : '<span class="text-faint">Pending</span>') +
            (w.monitoring_enabled ? '<div class="fs-12 text-muted">every ' + w.check_interval + ' min</div>' : '<div class="fs-12 text-muted">paused</div>') + '</td>' +
            '<td class="actions"><div class="row-actions">' +
            '<button type="button" class="btn-icon btn-sm bordered" data-row-action="check" data-id="' + w.id + '"' + (busy ? ' disabled' : '') +
            ' data-bs-toggle="tooltip" title="Check now" aria-label="Check ' + SW.escape(w.name) + ' now">' +
            (busy ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' : '<i class="bi bi-arrow-repeat" aria-hidden="true"></i>') +
            '</button>' + menu + '</div></td></tr>';
    };

    WebsiteTable.prototype.renderRows = function () {
        const body = this.el.querySelector('[data-body]');
        if (!body) return;
        if (!this.rows.length) {
            const hasFilter = this.state.q || this.state.filter !== 'all' || this.state.client;
            body.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + (hasFilter
                ? SW.emptyState('bi-funnel', 'No websites match these filters.', 'Try a different search term or clear the filters.',
                    '<button type="button" class="btn btn-sm btn-light" data-remove-filter="all">Clear filters</button>')
                : SW.emptyState('bi-globe2', 'No websites are being monitored yet.', 'Add your first client website to start monitoring uptime and health.',
                    '<a class="btn btn-sm btn-primary" href="' + SW.url('admin/website-add.php') + '"><i class="bi bi-plus-lg"></i> Add your first website</a>')) + '</td></tr>';
            this.syncSelection();
            return;
        }
        body.innerHTML = this.rows.map(this.rowHtml, this).join('');
        SW.tooltips(body);
        SW.dropdowns(body);
        this.syncSelection();
    };

    WebsiteTable.prototype.updateRow = function (w) {
        const idx = this.rows.findIndex(function (r) { return r.id === w.id; });
        if (idx === -1) return;
        this.rows[idx] = w;
        const tr = this.el.querySelector('[data-row="' + w.id + '"]');
        if (tr) {
            tr.outerHTML = this.rowHtml(w);
            const fresh = this.el.querySelector('[data-row="' + w.id + '"]');
            SW.tooltips(fresh);
            SW.dropdowns(fresh);
        }
    };

    WebsiteTable.prototype.syncSelection = function () {
        const bar = this.el.querySelector('[data-bulk]');
        const count = this.selected.size;
        if (bar) {
            bar.classList.toggle('show', count > 0);
            const c = bar.querySelector('[data-bulk-count]');
            if (c) c.textContent = count + (count === 1 ? ' website selected' : ' websites selected');
        }
        SW.qsa('[data-row]', this.el).forEach(function (tr) {
            tr.classList.toggle('selected', this.selected.has(parseInt(tr.getAttribute('data-row'), 10)));
        }, this);
        const all = this.el.querySelector('[data-select-all]');
        if (all) {
            const visible = this.rows.length;
            const sel = this.rows.filter(function (r) { return this.selected.has(r.id); }, this).length;
            all.checked = visible > 0 && sel === visible;
            all.indeterminate = sel > 0 && sel < visible;
        }
    };

    WebsiteTable.prototype.rowAction = async function (action, id) {
        const row = this.rows.find(function (r) { return r.id === id; });
        if (!row) return;

        if (action === 'check') {
            if (this.busy.has(id)) return;
            this.busy.add(id);
            this.updateRow(row);
            try {
                const res = await SW.api('api/websites/check.php', { method: 'POST', body: { id: id } });
                this.busy.delete(id);
                this.updateRow(res.data.website);
                const r = res.data.result;
                SW.toast('Check completed: ' + r.status_label + (r.http_status ? ' · HTTP ' + r.http_status : '') + (r.response_time !== null ? ' · ' + SW.fmt.ms(r.response_time) : ''),
                    r.severity === 'down' ? 'danger' : r.severity === 'warning' ? 'warning' : 'success', { title: row.name });
                if (res.data.incident_opened) SW.toast('Incident opened for ' + row.name, 'danger');
                if (res.data.incident_resolved) SW.toast('Website recovered: ' + row.name, 'success');
                document.dispatchEvent(new CustomEvent('sw:website-changed', { detail: { id: id } }));
            } catch (e) {
                this.busy.delete(id);
                this.updateRow(row);
                SW.toast(e.message, 'danger');
            }
            return;
        }

        if (action === 'pause' || action === 'resume') {
            try {
                const res = await SW.api('api/websites/pause.php', { method: 'POST', body: { id: id, action: action } });
                this.updateRow(res.data.website);
                SW.toast(res.message, 'success');
                document.dispatchEvent(new CustomEvent('sw:website-changed', { detail: { id: id } }));
            } catch (e) { SW.toast(e.message, 'danger'); }
            return;
        }

        if (action === 'delete') {
            const ok = await SW.confirm({
                title: 'Delete ' + row.name + '?',
                message: 'All checks, incidents and statistics for this website will be permanently removed.',
                confirmText: 'Delete website',
            });
            if (!ok) return;
            try {
                const res = await SW.api('api/websites/delete.php', { method: 'POST', body: { id: id } });
                SW.toast(res.message, 'success');
                this.selected.delete(id);
                await this.load();
                document.dispatchEvent(new CustomEvent('sw:website-changed', { detail: { id: id, deleted: true } }));
            } catch (e) { SW.toast(e.message, 'danger'); }
        }
    };

    WebsiteTable.prototype.bulk = async function (action, interval) {
        const ids = Array.from(this.selected);
        if (action === 'clear') { this.selected.clear(); this.syncSelection(); return; }
        if (!ids.length) { SW.toast('Select at least one website first.', 'warning'); return; }
        if (action === 'delete') {
            const ok = await SW.confirm({
                title: 'Delete ' + ids.length + ' website' + (ids.length === 1 ? '' : 's') + '?',
                message: 'All monitoring history for the selected websites will be permanently removed. This cannot be undone.',
                confirmText: 'Delete ' + ids.length + ' website' + (ids.length === 1 ? '' : 's'),
            });
            if (!ok) return;
        }
        const buttons = SW.qsa('[data-bulk-action]', this.el);
        buttons.forEach(function (b) { b.disabled = true; });
        try {
            const res = await SW.api('api/websites/bulk.php', { method: 'POST', body: { ids: ids, action: action, interval: interval || null } });
            SW.toast(res.message, 'success');
            if (action === 'delete') this.selected.clear();
            await this.load();
            document.dispatchEvent(new CustomEvent('sw:website-changed', { detail: { bulk: true } }));
        } catch (e) {
            SW.toast(e.message, 'danger');
        } finally {
            buttons.forEach(function (b) { b.disabled = false; });
        }
    };

    window.SW.WebsiteTable = WebsiteTable;

    // Websites page bootstrap
    document.addEventListener('sw:ready', function () {
        const mount = document.getElementById('websitesPage');
        if (!mount) return;
        const table = new SW.WebsiteTable(mount, {
            perPage: 25,
            storageKey: 'sw-websites-page',
            title: 'Website inventory',
            subtitle: '',
            exportUrl: SW.url('api/websites/export.php'),
        });
        const exportLink = mount.querySelector('[data-export]');
        if (exportLink) {
            exportLink.addEventListener('click', function () {
                exportLink.href = SW.url('api/websites/export.php', { q: table.state.q, filter: table.state.filter, client: table.state.client });
            });
        }
        SW.poll(function () { return table.refresh(true); }, Math.max(15000, (SW.config.refresh || 30) * 1000));
    });
})();
