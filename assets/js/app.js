/* ==========================================================================
   SiteWatch — core front-end runtime (vanilla JS, no jQuery)
   Exposes window.SW with: api, toast, confirm, theme, fmt, badge, favicon, poll, escape, ...
   ========================================================================== */
(function () {
    'use strict';

    const cfgEl = document.getElementById('sw-config');
    let config = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : {};
    const pageEl = document.getElementById('sw-page-data');
    const pageData = pageEl ? JSON.parse(pageEl.textContent || '{}') : {};

    const SW = {
        config: config,
        page: pageData,
        baseUrl: (config.baseUrl || '').replace(/\/$/, ''),
        csrf: config.csrf || (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
        thresholds: config.thresholds || { moderate: 2000, slow: 5000, critical: 10000 },
    };

    // ------------------------------------------------------------------
    // Page scope
    //
    // Pages are swapped in place (see the router below), so everything a page script starts must stop
    // when the user leaves: document/window listeners added while the page boots, intervals, polls and
    // charts. Page scripts need no changes for this; the scope records what they create.
    // ------------------------------------------------------------------
    const nativeAdd = EventTarget.prototype.addEventListener;
    const nativeSetInterval = window.setInterval.bind(window);
    let coreDepth = 0;
    let booting = false;
    SW._generation = 0;
    SW._scope = { listeners: [], intervals: [], polls: [] };

    const trackingListener = function (target) {
        return function (type, fn, opts) {
            if (booting && coreDepth === 0) SW._scope.listeners.push([target, type, fn, opts]);
            return nativeAdd.call(target, type, fn, opts);
        };
    };
    document.addEventListener = trackingListener(document);
    window.addEventListener = trackingListener(window);
    window.setInterval = function (fn, ms) {
        const id = nativeSetInterval.apply(null, arguments);
        if (coreDepth === 0) SW._scope.intervals.push(id);
        return id;
    };
    /** Runs fn as app-wide code: nothing it registers is torn down on navigation. */
    SW.core = function (fn) { coreDepth++; try { return fn(); } finally { coreDepth--; } };

    SW._teardown = function () {
        const scope = SW._scope;
        scope.listeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
        scope.intervals.forEach(function (id) { clearInterval(id); });
        scope.polls.forEach(function (p) { p.stop(); });
        if (window.Chart && Chart.instances) { Object.values(Chart.instances).forEach(function (c) { try { c.destroy(); } catch (e) { /* ignore */ } }); }
        if (window.bootstrap) {
            SW.qsa('.modal.show').forEach(function (m) { const i = bootstrap.Modal.getInstance(m); if (i) i.hide(); });
            SW.qsa('#mainContent [data-bs-toggle="tooltip"]').forEach(function (el) { const t = bootstrap.Tooltip.getInstance(el); if (t) t.dispose(); });
        }
        SW.qsa('.tooltip, .popover').forEach(function (el) { el.remove(); });
        SW.qsa('.modal-backdrop').forEach(function (el) { el.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        SW.panel.current = null;
        SW._generation++;
        SW._scope = { listeners: [], intervals: [], polls: [] };
    };

    // ------------------------------------------------------------------
    // Utilities
    // ------------------------------------------------------------------
    SW.escape = function (value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };

    SW.url = function (path, params) {
        let url = SW.baseUrl + '/' + String(path).replace(/^\//, '');
        if (params) {
            const qs = new URLSearchParams();
            Object.keys(params).forEach(function (k) {
                const v = params[k];
                if (v === undefined || v === null || v === '') return;
                if (Array.isArray(v)) { v.forEach(function (i) { qs.append(k + '[]', i); }); } else { qs.set(k, v); }
            });
            const s = qs.toString();
            if (s) url += (url.indexOf('?') === -1 ? '?' : '&') + s;
        }
        return url;
    };

    SW.debounce = function (fn, wait) {
        let t = null;
        return function () {
            const args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait || 250);
        };
    };

    SW.qs = function (sel, root) { return (root || document).querySelector(sel); };
    SW.qsa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    SW.storage = {
        get: function (key, fallback) { try { const v = localStorage.getItem(key); return v === null ? fallback : JSON.parse(v); } catch (e) { return fallback; } },
        set: function (key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* ignore */ } },
    };

    /**
     * Whether the signed-in user's role grants a permission. Only used to hide controls:
     * the server checks every request.
     */
    SW.can = function (permission) {
        return (config.permissions || []).indexOf(permission) !== -1;
    };

    // ------------------------------------------------------------------
    // Fetch wrapper
    // ------------------------------------------------------------------
    SW.api = async function (path, options) {
        options = options || {};
        const method = (options.method || 'GET').toUpperCase();
        const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': SW.csrf };
        const init = { method: method, headers: headers, credentials: 'same-origin' };
        let url = SW.url(path, options.query);
        if (options.body !== undefined && method !== 'GET') {
            if (options.body instanceof FormData) {
                options.body.append('_token', SW.csrf);
                init.body = options.body;
            } else {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(Object.assign({ _token: SW.csrf }, options.body || {}));
            }
        }
        if (options.signal) init.signal = options.signal;

        // Page requests are dropped after navigation; app-wide ones (options.global) always settle.
        const generation = options.global ? -1 : SW._generation;
        let response;
        try {
            response = await fetch(url, init);
        } catch (err) {
            if (generation !== -1 && generation !== SW._generation) return new Promise(function () {});
            if (err && err.name === 'AbortError') throw err;
            const e = new Error('Network error. Please check your connection and try again.');
            e.network = true;
            throw e;
        }
        let payload = null;
        try { payload = await response.json(); } catch (e) { payload = null; }
        // The user has moved to another page: drop the answer instead of rendering into the new one.
        if (generation !== -1 && generation !== SW._generation) return new Promise(function () {});

        if (response.status === 401) {
            SW.toast('Your session has expired. Redirecting to sign in…', 'warning');
            setTimeout(function () { window.location.href = SW.url('login.php'); }, 1200);
            const e = new Error('Authentication required'); e.status = 401; throw e;
        }
        if (!payload || typeof payload !== 'object') {
            const e = new Error('Unexpected server response (' + response.status + ').'); e.status = response.status; throw e;
        }
        if (!response.ok || payload.success === false) {
            const e = new Error(payload.message || 'Request failed.');
            e.status = response.status;
            e.errors = payload.errors || {};
            e.payload = payload;
            throw e;
        }
        return payload;
    };

    // ------------------------------------------------------------------
    // Toasts
    // ------------------------------------------------------------------
    SW.toast = function (message, type, opts) {
        type = type || 'success';
        opts = opts || {};
        const container = document.getElementById('toastContainer');
        if (!container) { return; }
        const icons = { success: 'bi-check-circle-fill', danger: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        const el = document.createElement('div');
        el.className = 'toast t-' + type;
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.innerHTML = '<div class="toast-body"><i class="bi ' + (icons[type] || icons.info) + ' toast-icon"></i><div class="flex-grow-1">' +
            (opts.title ? '<div class="fw-600">' + SW.escape(opts.title) + '</div>' : '') +
            '<div>' + (opts.html ? message : SW.escape(message)) + '</div></div>' +
            '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button></div>';
        container.appendChild(el);
        const toast = new bootstrap.Toast(el, { delay: opts.delay || (type === 'danger' ? 7000 : 4000) });
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        toast.show();
        return toast;
    };

    // ------------------------------------------------------------------
    // Confirm dialog
    // ------------------------------------------------------------------
    SW.confirm = function (opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            const modalEl = document.getElementById('swConfirmModal');
            if (!modalEl) { resolve(window.confirm(opts.message || 'Are you sure?')); return; }
            SW.qs('#swConfirmTitle').textContent = opts.title || 'Are you sure?';
            SW.qs('#swConfirmMessage').textContent = opts.message || '';
            const ok = SW.qs('#swConfirmOk');
            ok.textContent = opts.confirmText || 'Confirm';
            ok.className = 'btn ' + (opts.danger === false ? 'btn-primary' : 'btn-danger');
            const icon = SW.qs('#swConfirmIcon');
            icon.className = 'activity-icon flex-shrink-0 ' + (opts.danger === false ? 'tone-primary' : 'tone-danger');
            icon.innerHTML = '<i class="bi ' + (opts.icon || (opts.danger === false ? 'bi-question-circle' : 'bi-exclamation-triangle')) + '"></i>';
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            let decided = false;
            const onOk = function () { decided = true; modal.hide(); resolve(true); };
            const onHide = function () { ok.removeEventListener('click', onOk); modalEl.removeEventListener('hidden.bs.modal', onHide); if (!decided) resolve(false); };
            ok.addEventListener('click', onOk);
            modalEl.addEventListener('hidden.bs.modal', onHide);
            modal.show();
        });
    };

    // ------------------------------------------------------------------
    // Theme & sidebar
    // ------------------------------------------------------------------
    SW.theme = {
        current: function () { return document.documentElement.getAttribute('data-bs-theme') || 'light'; },
        apply: function (theme) {
            document.documentElement.setAttribute('data-bs-theme', theme);
            try { localStorage.setItem('sw-theme', theme); } catch (e) { /* ignore */ }
            SW.qsa('#themeToggle i, .auth-theme-toggle i').forEach(function (i) { i.className = 'bi ' + (theme === 'dark' ? 'bi-sun' : 'bi-moon-stars'); });
            document.dispatchEvent(new CustomEvent('sw:theme', { detail: { theme: theme } }));
        },
        toggle: function () { SW.theme.apply(SW.theme.current() === 'dark' ? 'light' : 'dark'); },
    };

    SW.chartColors = function () {
        const dark = SW.theme.current() === 'dark';
        return {
            grid: dark ? '#262B31' : '#E9EEF4',
            text: dark ? '#A8B0BA' : '#64748B',
            primary: dark ? '#FB923C' : '#EA580C',
            primarySoft: dark ? 'rgba(251,146,60,0.16)' : 'rgba(234,88,12,0.10)',
            success: dark ? '#22C55E' : '#16A34A',
            successSoft: dark ? 'rgba(34,197,94,0.16)' : 'rgba(22,163,74,0.10)',
            warning: dark ? '#FBBF24' : '#D97706',
            danger: dark ? '#EF4444' : '#DC2626',
            info: dark ? '#93C5FD' : '#1D4ED8',
            neutral: dark ? '#6B7480' : '#94A3B8',
            tooltipBg: dark ? '#E5E7EB' : '#111827',
            tooltipText: dark ? '#111827' : '#FFFFFF',
        };
    };

    /**
     * Shared Chart.js defaults. Every chart page calls this before building a
     * chart so legends, tooltips and typography stay consistent.
     */
    SW.chartDefaults = function () {
        const c = SW.chartColors();
        if (typeof Chart === 'undefined') return c;
        Chart.defaults.color = c.text;
        Chart.defaults.borderColor = c.grid;
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 11.5;
        Chart.defaults.animation = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : Chart.defaults.animation;
        Chart.defaults.plugins.legend.display = false;
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        Chart.defaults.plugins.legend.labels.boxHeight = 10;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.tooltip.backgroundColor = c.tooltipBg;
        Chart.defaults.plugins.tooltip.titleColor = c.tooltipText;
        Chart.defaults.plugins.tooltip.bodyColor = c.tooltipText;
        Chart.defaults.plugins.tooltip.cornerRadius = 6;
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.displayColors = false;
        return c;
    };

    // ------------------------------------------------------------------
    // Formatting
    // ------------------------------------------------------------------
    SW.fmt = {
        ms: function (ms) {
            if (ms === null || ms === undefined || ms === '') return '—';
            ms = Number(ms);
            if (ms < 1000) return Math.round(ms) + ' ms';
            return (ms / 1000).toFixed(2) + ' s';
        },
        uptime: function (pct) {
            if (pct === null || pct === undefined || pct === '') return '—';
            pct = Number(pct);
            if (pct >= 100) return '100%';
            return pct.toFixed(2) + '%';
        },
        num: function (n) { return (n === null || n === undefined) ? '—' : Number(n).toLocaleString(); },
        duration: function (seconds) {
            seconds = Math.max(0, Math.round(Number(seconds) || 0));
            if (seconds < 60) return seconds + ' sec';
            if (seconds < 3600) { const m = Math.floor(seconds / 60); return m + (m === 1 ? ' minute' : ' minutes'); }
            if (seconds < 86400) { const h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60); return h + 'h' + (m ? ' ' + m + 'm' : ''); }
            const d = Math.floor(seconds / 86400), h = Math.floor((seconds % 86400) / 3600); return d + 'd' + (h ? ' ' + h + 'h' : '');
        },
        timeAgo: function (utc) {
            if (!utc) return 'Never';
            const then = SW.fmt.parseUtc(utc);
            if (!then) return 'Never';
            let diff = Math.max(0, Math.round((Date.now() - then.getTime()) / 1000));
            if (diff < 5) return 'just now';
            if (diff < 60) return diff + ' sec ago';
            if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
            if (diff < 86400) { const h = Math.floor(diff / 3600); return h + (h === 1 ? ' hour ago' : ' hours ago'); }
            const d = Math.floor(diff / 86400);
            if (d < 30) return d + (d === 1 ? ' day ago' : ' days ago');
            return SW.fmt.date(utc);
        },
        parseUtc: function (utc) {
            if (!utc) return null;
            if (utc instanceof Date) return utc;
            const m = String(utc).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
            if (!m) { const d = new Date(utc); return isNaN(d.getTime()) ? null : d; }
            return new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]));
        },
        date: function (utc, withTime) {
            const d = SW.fmt.parseUtc(utc);
            if (!d) return '—';
            const opts = { day: 'numeric', month: 'short', year: 'numeric', timeZone: config.timezone || undefined };
            if (withTime) { opts.hour = 'numeric'; opts.minute = '2-digit'; }
            try { return new Intl.DateTimeFormat('en-GB', opts).format(d).replace(',', ' ·'); } catch (e) { return d.toLocaleString(); }
        },
        rtClass: function (ms) {
            if (ms === null || ms === undefined) return '';
            ms = Number(ms);
            if (ms >= SW.thresholds.critical) return 'critical';
            if (ms >= SW.thresholds.slow) return 'slow';
            if (ms >= SW.thresholds.moderate) return 'moderate';
            return 'fast';
        },
        httpClass: function (code) {
            if (!code) return '';
            code = Number(code);
            if (code >= 500) return 'bad';
            if (code >= 400) return 'warn';
            if (code >= 200 && code < 300) return 'ok';
            return '';
        },
    };

    // ------------------------------------------------------------------
    // Render helpers
    // ------------------------------------------------------------------
    SW.badge = function (status, label, severity, extraClass) {
        severity = severity || 'neutral';
        return '<span class="sw-badge sev-' + SW.escape(severity) + (extraClass ? ' ' + extraClass : '') + '" data-status="' + SW.escape(status) + '">' + SW.escape(label || status) + '</span>';
    };
    SW.pill = function (label, tone, icon) {
        return '<span class="sw-pill tone-' + SW.escape(tone || 'neutral') + '">' + (icon ? '<i class="bi ' + SW.escape(icon) + '"></i>' : '') + SW.escape(label) + '</span>';
    };
    SW.httpCode = function (code) {
        if (!code) return '<span class="text-faint">—</span>';
        return '<span class="http-code ' + SW.fmt.httpClass(code) + '">' + SW.escape(code) + '</span>';
    };
    SW.responseTime = function (ms) {
        if (ms === null || ms === undefined) return '<span class="text-faint">—</span>';
        return '<span class="rt ' + SW.fmt.rtClass(ms) + '">' + SW.escape(SW.fmt.ms(ms)) + '</span>';
    };
    SW.favicon = function (url, domain, large) {
        const cls = large ? ' lg' : '';
        if (!url) return '<span class="sw-favicon-fallback' + cls + '"><i class="bi bi-globe2"></i></span>';
        return '<img class="sw-favicon' + cls + '" src="' + SW.escape(url) + '" alt="" loading="lazy" width="28" height="28" data-domain="' + SW.escape(domain || '') + '">';
    };
    /**
     * The one website cell used by every table: favicon, name (linked to the details page) and, on the
     * second line, the domain and client. opts.href overrides the link; opts.extra adds HTML after the name.
     */
    SW.siteCell = function (r, opts) {
        opts = opts || {};
        const href = opts.href || SW.url('admin/website-details.php', { id: r.id });
        const sub = [];
        if (r.domain) sub.push('<span class="site-domain-text">' + SW.escape(r.domain) + '</span>');
        if (r.client_name) sub.push('<span class="site-client">' + SW.escape(r.client_name) + '</span>');
        return '<div class="site-cell">' + SW.favicon(r.favicon_url, r.domain) +
            '<div class="site-text"><div class="site-name-row"><a class="site-name" href="' + SW.escape(href) + '" title="' + SW.escape(r.name) + '">' + SW.escape(r.name) + '</a>' + (opts.extra || '') + '</div>' +
            (sub.length ? '<div class="site-sub s-sub" title="' + SW.escape([r.domain, r.client_name].filter(Boolean).join(' · ')) + '">' + sub.join('<span class="sep" aria-hidden="true">·</span>') + '</div>' : '') +
            '</div></div>';
    };

    /**
     * Side panel (details that should not push the list around). Markup: <aside class="sw-panel" id="x"> with an
     * optional <div class="sw-panel-backdrop" id="xBackdrop">; [data-panel-close] inside closes it, as do Esc and
     * a click on the backdrop.
     */
    SW.panel = {
        current: null, lastFocus: null,
        open: function (id) {
            const panel = document.getElementById(id);
            if (!panel) return;
            if (SW.panel.current && SW.panel.current !== id) SW.panel.close();
            SW.panel.lastFocus = SW.panel.current ? SW.panel.lastFocus : document.activeElement;
            SW.panel.current = id;
            const backdrop = document.getElementById(id + 'Backdrop');
            if (backdrop) backdrop.hidden = false;
            panel.classList.add('show');
            panel.setAttribute('aria-hidden', 'false');
            setTimeout(function () { panel.focus(); }, 30);
        },
        close: function () {
            const id = SW.panel.current;
            if (!id) return;
            const panel = document.getElementById(id);
            const backdrop = document.getElementById(id + 'Backdrop');
            if (panel) { panel.classList.remove('show'); panel.setAttribute('aria-hidden', 'true'); }
            if (backdrop) backdrop.hidden = true;
            SW.panel.current = null;
            if (SW.panel.lastFocus && document.contains(SW.panel.lastFocus)) SW.panel.lastFocus.focus();
        },
    };

    /** Shows a chart's empty state in place of the canvas (the box keeps its height). */
    SW.chartEmpty = function (canvas, empty, icon, title, text) {
        const box = document.getElementById(empty);
        if (!box) return;
        box.hidden = !title;
        box.innerHTML = title ? SW.emptyState(icon, title, text) : '';
        if (canvas) canvas.style.visibility = title ? 'hidden' : '';
    };

    SW.timeAgoEl = function (utc) {
        return '<span data-timeago="' + SW.escape(utc || '') + '" title="' + SW.escape(SW.fmt.date(utc, true)) + '">' + SW.escape(SW.fmt.timeAgo(utc)) + '</span>';
    };
    SW.emptyState = function (icon, title, text, cta) {
        return '<div class="empty-state"><div class="ico"><i class="bi ' + SW.escape(icon) + '"></i></div><h4>' + SW.escape(title) + '</h4>' +
            (text ? '<p>' + SW.escape(text) + '</p>' : '') + (cta || '') + '</div>';
    };
    SW.skeletonRows = function (cols, rows) {
        let html = '';
        for (let r = 0; r < (rows || 5); r++) {
            html += '<tr>';
            for (let c = 0; c < cols; c++) { html += '<td><div class="skeleton skeleton-line" style="width:' + (40 + ((r * 7 + c * 13) % 50)) + '%">&nbsp;</div></td>'; }
            html += '</tr>';
        }
        return html;
    };
    SW.pagination = function (container, page, perPage, total, onPage) {
        if (!container) return;
        const pages = Math.max(1, Math.ceil(total / perPage));
        const from = total === 0 ? 0 : (page - 1) * perPage + 1;
        const to = Math.min(total, page * perPage);
        let html = '<div>Showing <b>' + from + '–' + to + '</b> of <b>' + SW.fmt.num(total) + '</b></div><div class="pages">';
        html += '<button type="button" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>';
        const windowSize = 2;
        let last = 0;
        for (let p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || (p >= page - windowSize && p <= page + windowSize)) {
                if (last && p - last > 1) html += '<button type="button" disabled>…</button>';
                html += '<button type="button" data-page="' + p + '" class="' + (p === page ? 'active' : '') + '">' + p + '</button>';
                last = p;
            }
        }
        html += '<button type="button" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button></div>';
        container.innerHTML = html;
        SW.qsa('button[data-page]', container).forEach(function (b) {
            b.addEventListener('click', function () { onPage(parseInt(b.getAttribute('data-page'), 10)); });
        });
    };
    SW.setLoading = function (btn, loading, label) {
        if (!btn) return;
        if (loading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.classList.add('is-loading');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>' + (label ? ' ' + SW.escape(label) : '');
        } else {
            btn.classList.remove('is-loading');
            btn.disabled = false;
            if (btn.dataset.originalHtml !== undefined) { btn.innerHTML = btn.dataset.originalHtml; delete btn.dataset.originalHtml; }
        }
    };
    SW.showErrors = function (form, errors) {
        SW.qsa('.is-invalid', form).forEach(function (el) { el.classList.remove('is-invalid'); });
        SW.qsa('.invalid-feedback.dynamic', form).forEach(function (el) { el.remove(); });
        let first = null;
        Object.keys(errors || {}).forEach(function (field) {
            const input = form.querySelector('[name="' + field + '"]');
            if (!input) return;
            input.classList.add('is-invalid');
            const fb = document.createElement('div');
            fb.className = 'invalid-feedback dynamic d-block';
            fb.textContent = errors[field];
            (input.closest('.input-group') || input).insertAdjacentElement('afterend', fb);
            if (!first) first = input;
        });
        if (first) {
            // Reveal any collapsed section containing an invalid field.
            let parent = first.closest('details');
            while (parent) { parent.open = true; parent = parent.parentElement ? parent.parentElement.closest('details') : null; }
            const pane = first.closest('.tab-pane');
            if (pane && !pane.classList.contains('active')) {
                const trigger = document.querySelector('[data-bs-target="#' + pane.id + '"]');
                if (trigger && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(trigger).show(); }
            }
            first.focus();
            if (typeof first.scrollIntoView === 'function') first.scrollIntoView({ block: 'center' });
        }
    };

    /**
     * Dropdowns inside horizontally scrolling containers (data tables) must not
     * be clipped: the fixed positioning strategy lifts the menu out of the
     * scroll box so row actions stay reachable at every width.
     */
    SW.dropdowns = function (root) {
        if (!window.bootstrap) return;
        SW.qsa('[data-bs-toggle="dropdown"]', root).forEach(function (el) {
            if (bootstrap.Dropdown.getInstance(el)) return;
            new bootstrap.Dropdown(el, { popperConfig: { strategy: 'fixed' } });
        });
    };
    SW.tooltips = function (root) {
        SW.qsa('[data-bs-toggle="tooltip"]', root).forEach(function (el) {
            const existing = bootstrap.Tooltip.getInstance(el);
            if (existing) existing.dispose();
            new bootstrap.Tooltip(el, { container: 'body' });
        });
    };

    // ------------------------------------------------------------------
    // Polling (visibility aware, no overlap)
    // ------------------------------------------------------------------
    SW.poll = function (fn, intervalMs, opts) {
        opts = opts || {};
        let timer = null, running = false, stopped = false;
        const indicator = document.getElementById('refreshIndicator');
        const setIndicator = function (active) {
            if (!indicator || indicator.classList.contains('failed')) return;
            indicator.classList.toggle('paused', !active);
            const txt = indicator.querySelector('.txt');
            if (txt) txt.textContent = active ? 'Auto-refresh' : 'Refresh paused';
        };
        const tick = async function () {
            if (stopped) return;
            if (document.hidden) { setIndicator(false); schedule(); return; }
            setIndicator(true);
            if (running) { schedule(); return; }
            running = true;
            try { await fn(); } catch (e) { if (!e || e.status !== 401) console.warn('poll error', e); } finally { running = false; schedule(); }
        };
        const schedule = function () { clearTimeout(timer); if (!stopped) timer = setTimeout(tick, intervalMs); };
        const onVisible = function () { if (!document.hidden && !stopped) { clearTimeout(timer); tick(); } };
        nativeAdd.call(document, 'visibilitychange', onVisible);
        if (opts.immediate) { tick(); } else { schedule(); }
        const handle = {
            stop: function () { stopped = true; clearTimeout(timer); document.removeEventListener('visibilitychange', onVisible); },
            now: function () { clearTimeout(timer); tick(); },
        };
        // Polls started by a page stop when the user leaves it.
        if (coreDepth === 0) SW._scope.polls.push(handle);
        return handle;
    };


    // ------------------------------------------------------------------
    // Engine status chip & incident count
    // ------------------------------------------------------------------
    SW.updateEngine = function (engine) {
        if (!engine) return;
        const detail = engine.message || ('Last monitoring run: ' + (engine.last_run_ago || 'never'));
        const el = document.getElementById('engineStatus');
        if (!el) return;
        el.className = 'sw-engine state-' + engine.state;
        const label = el.querySelector('[data-engine-label]');
        const meta = el.querySelector('[data-engine-meta]');
        if (label) label.textContent = engine.state === 'unknown' ? 'Status unavailable' : engine.label;
        if (meta) meta.textContent = engine.last_run_ago && engine.last_run_ago !== 'never' ? 'Last run ' + engine.last_run_ago : 'Never run';
        el.setAttribute('data-bs-original-title', detail);
        el.setAttribute('title', detail);
    };

    SW.updateIncidentCount = function (count) {
        count = Number(count) || 0;
        const el = document.getElementById('navIncidentCount');
        if (el) el.textContent = count > 0 ? String(count) : '';
        config.openIncidents = count;
        // Installed app: open incidents on the taskbar / Dock icon.
        try {
            if (count > 0 && navigator.setAppBadge) { navigator.setAppBadge(count).catch(function () {}); }
            else if (navigator.clearAppBadge) { navigator.clearAppBadge().catch(function () {}); }
        } catch (e) { /* unsupported */ }
    };

    // ------------------------------------------------------------------
    // Top progress bar (only appears when a page takes longer than a moment)
    // ------------------------------------------------------------------
    const progress = {
        el: null, showTimer: null, trickle: null, value: 0,
        set: function (v) { this.value = v; if (this.el) this.el.firstElementChild.style.width = (v * 100) + '%'; },
        start: function () {
            const self = this;
            self.el = self.el || document.getElementById('swProgress');
            if (!self.el) return;
            clearTimeout(self.showTimer); clearInterval(self.trickle);
            self.el.classList.remove('is-active', 'is-done');
            self.set(0);
            self.showTimer = setTimeout(function () {
                self.el.classList.add('is-active');
                self.set(0.25);
                self.trickle = nativeSetInterval(function () { if (self.value < 0.9) self.set(self.value + (0.9 - self.value) * 0.12); }, 200);
            }, 120);
        },
        done: function () {
            const self = this;
            clearTimeout(self.showTimer); clearInterval(self.trickle);
            if (!self.el || !self.el.classList.contains('is-active')) return;
            self.set(1);
            self.el.classList.add('is-done');
            setTimeout(function () { self.el.classList.remove('is-active', 'is-done'); self.set(0); }, 260);
        },
    };
    SW.progress = progress;

    // ------------------------------------------------------------------
    // In-app navigation
    //
    // Links to other admin pages are fetched in the background and only <main> is replaced; the sidebar,
    // top bar and loaded libraries stay. Hovering a link starts the download early. Anything unusual
    // (a redirect to sign in, a non-HTML answer, a network error) falls back to a normal page load.
    // ------------------------------------------------------------------
    let closeSidebar = function () {};
    let bootPage = function () {};

    const router = {
        prefetched: new Map(),
        snapshots: new Map(),
        loadedScripts: new Set(),
        busy: null,
        adminPath: function () { return new URL(SW.baseUrl + '/admin/', window.location.href).pathname; },

        eligible: function (a, ev) {
            if (!a || !a.getAttribute('href')) return null;
            if (a.target && a.target !== '_self') return null;
            if (a.hasAttribute('download') || a.closest('[data-no-swap]')) return null;
            if (ev && (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || (ev.button !== undefined && ev.button !== 0))) return null;
            let u;
            try { u = new URL(a.href, window.location.href); } catch (e) { return null; }
            if (u.origin !== window.location.origin) return null;
            if (u.pathname.indexOf(router.adminPath()) !== 0 || !/\.php$/.test(u.pathname)) return null;
            if (u.pathname === window.location.pathname && u.search === window.location.search && u.hash) return null;
            return u;
        },

        key: function (u) { const c = new URL(u, window.location.href); c.hash = ''; return c.href; },

        fetchPage: function (href) {
            const key = router.key(href);
            const hit = router.prefetched.get(key);
            router.prefetched.delete(key);
            if (hit && Date.now() - hit.at < 15000) return hit.promise;
            return fetch(key, { credentials: 'same-origin', headers: { 'Accept': 'text/html', 'X-SW-Nav': '1' } })
                .then(function (res) {
                    const type = res.headers.get('Content-Type') || '';
                    return res.text().then(function (text) { return { url: res.url, html: type.indexOf('text/html') !== -1 ? text : null, status: res.status }; });
                });
        },

        prefetch: function (u) {
            const key = router.key(u.href);
            if (key === router.key(window.location.href) || router.prefetched.has(key)) return;
            if (navigator.connection && navigator.connection.saveData) return;
            const promise = router.fetchPage(key);
            promise.catch(function () { router.prefetched.delete(key); });
            router.prefetched.set(key, { at: Date.now(), promise: promise });
        },

        saveSnapshot: function () {
            const main = document.getElementById('mainContent');
            if (!main) return;
            router.snapshots.set(router.key(window.location.href), { html: main.innerHTML, title: document.title });
            if (router.snapshots.size > 12) router.snapshots.delete(router.snapshots.keys().next().value);
            try { history.replaceState(Object.assign({}, history.state, { sw: 1, scroll: window.scrollY }), ''); } catch (e) { /* ignore */ }
        },

        runScript: function (src) {
            return new Promise(function (resolve) {
                const s = document.createElement('script');
                s.src = src;
                s.onload = s.onerror = function () { s.remove(); resolve(); };
                document.body.appendChild(s);
            });
        },

        syncChrome: function (doc) {
            const active = doc.querySelector('.sw-nav-link.active');
            const key = active ? active.getAttribute('data-nav') : null;
            SW.qsa('.sw-sidebar .sw-nav-link').forEach(function (a) {
                const on = a.getAttribute('data-nav') === key;
                a.classList.toggle('active', on);
                if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
            });
            ['.sw-user', '.sw-version'].forEach(function (sel) {
                const src = doc.querySelector(sel);
                const dst = document.querySelector(sel);
                if (src && dst) dst.classList.toggle('active', src.classList.contains('active'));
            });
            const count = doc.getElementById('navIncidentCount');
            if (count) SW.updateIncidentCount(count.textContent.trim());
            const engine = doc.getElementById('engineStatus');
            const mine = document.getElementById('engineStatus');
            if (engine && mine) {
                mine.className = engine.className;
                ['[data-engine-label]', '[data-engine-meta]'].forEach(function (sel) {
                    const a = engine.querySelector(sel), b = mine.querySelector(sel);
                    if (a && b) b.textContent = a.textContent;
                });
            }
        },

        go: async function (href, opts) {
            opts = opts || {};
            const main = document.getElementById('mainContent');
            if (!main) { window.location.href = href; return; }
            const ticket = {};
            router.busy = ticket;
            progress.start();
            let page;
            try { page = await router.fetchPage(href); } catch (e) { window.location.href = href; return; }
            if (router.busy !== ticket) return;

            const finalUrl = new URL(page.url || href, window.location.href);
            finalUrl.hash = new URL(href, window.location.href).hash;
            if (!page.html || finalUrl.pathname.indexOf(router.adminPath()) !== 0) { window.location.href = finalUrl.href; return; }
            const doc = new DOMParser().parseFromString(page.html, 'text/html');
            const newMain = doc.getElementById('mainContent');
            const cfg = doc.getElementById('sw-config');
            if (!newMain || !cfg) { window.location.href = finalUrl.href; return; }
            // After a deploy the page asks for newer styles or scripts than the ones loaded: load it normally.
            const assetList = function (root) {
                return Array.prototype.map.call(root.querySelectorAll('link[rel="stylesheet"], script[src]:not([data-page-script])'), function (el) {
                    return el.getAttribute('href') || el.getAttribute('src');
                }).filter(function (u) { return u && u.indexOf('chart.umd') === -1; }).sort().join('|');
            };
            if (assetList(doc) !== assetList(document)) { window.location.href = finalUrl.href; return; }

            let newConfig, newPage;
            try {
                newConfig = JSON.parse(cfg.textContent || '{}');
                const pd = doc.getElementById('sw-page-data');
                newPage = pd ? JSON.parse(pd.textContent || '{}') : {};
            } catch (e) { window.location.href = finalUrl.href; return; }

            if (!opts.fromHistory) router.saveSnapshot();
            SW._teardown();
            closeSidebar(false);

            main.innerHTML = newMain.innerHTML;
            main.classList.remove('is-preview', 'sw-enter');
            void main.offsetWidth;
            main.classList.add('sw-enter');
            document.title = doc.title;
            config = newConfig;
            SW.config = config;
            SW.page = newPage;
            SW.csrf = config.csrf || SW.csrf;
            SW.thresholds = config.thresholds || SW.thresholds;
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) meta.content = SW.csrf;
            router.syncChrome(doc);
            doc.querySelectorAll('[data-flash]').forEach(function (el) { document.body.appendChild(document.importNode(el, true)); });

            if (opts.fromHistory) {
                window.scrollTo(0, opts.scroll || 0);
            } else {
                history[opts.replace ? 'replaceState' : 'pushState']({ sw: 1, scroll: 0 }, '', finalUrl.href);
                const target = finalUrl.hash ? document.getElementById(decodeURIComponent(finalUrl.hash.slice(1))) : null;
                if (target) target.scrollIntoView(); else window.scrollTo(0, 0);
            }

            booting = true;
            try {
                const scripts = Array.prototype.slice.call(doc.querySelectorAll('body script[src]'));
                for (let i = 0; i < scripts.length; i++) {
                    const src = scripts[i].getAttribute('src');
                    const bare = src.split('?')[0];
                    if (scripts[i].hasAttribute('data-page-script')) { await router.runScript(src); }
                    else if (!router.loadedScripts.has(bare)) { router.loadedScripts.add(bare); await router.runScript(src); }
                    if (router.busy !== ticket) return;
                }
            } finally { booting = false; }
            bootPage();
            progress.done();
            router.busy = null;
            try { main.focus({ preventScroll: true }); } catch (e) { main.focus(); }
            document.dispatchEvent(new CustomEvent('sw:navigated', { detail: { url: finalUrl.href } }));
        },

        init: function () {
            SW.qsa('script[src]').forEach(function (s) { if (!s.hasAttribute('data-page-script')) router.loadedScripts.add(s.getAttribute('src').split('?')[0]); });
            if (!document.getElementById('mainContent') || !window.history || !window.DOMParser) return;
            try { history.scrollRestoration = 'manual'; } catch (e) { /* ignore */ }
            history.replaceState(Object.assign({}, history.state, { sw: 1 }), '');

            nativeAdd.call(document, 'click', function (ev) {
                if (ev.defaultPrevented) return;
                const a = ev.target.closest ? ev.target.closest('a[href]') : null;
                const u = router.eligible(a, ev);
                if (!u) return;
                ev.preventDefault();
                router.go(u.href);
            });
            let hoverTimer = null;
            const onIntent = function (ev) {
                const a = ev.target.closest ? ev.target.closest('a[href]') : null;
                const u = router.eligible(a);
                if (!u) return;
                clearTimeout(hoverTimer);
                hoverTimer = setTimeout(function () { router.prefetch(u); }, 65);
            };
            nativeAdd.call(document, 'mouseover', onIntent);
            nativeAdd.call(document, 'focusin', onIntent);
            nativeAdd.call(document, 'mouseout', function () { clearTimeout(hoverTimer); });
            nativeAdd.call(document, 'touchstart', function (ev) {
                const u = router.eligible(ev.target.closest ? ev.target.closest('a[href]') : null);
                if (u) router.prefetch(u);
            }, { passive: true });

            nativeAdd.call(window, 'popstate', function (ev) {
                const main = document.getElementById('mainContent');
                const snap = router.snapshots.get(router.key(window.location.href));
                const scroll = ev.state && ev.state.scroll ? ev.state.scroll : 0;
                if (snap && main) {
                    // Show the page as it was straight away, then bring it up to date.
                    SW._teardown();
                    main.innerHTML = snap.html;
                    main.classList.add('is-preview');
                    document.title = snap.title;
                    window.scrollTo(0, scroll);
                }
                router.go(window.location.href, { fromHistory: true, scroll: scroll });
            });
        },
    };

    /** Opens an admin page in place (or loads it normally when it is not an admin page). */
    SW.navigate = function (href, opts) {
        const a = document.createElement('a');
        a.href = href;
        const u = router.eligible(a);
        if (u) { router.go(u.href, opts); } else { window.location.href = href; }
    };

    // ------------------------------------------------------------------
    // Command palette (Ctrl/Cmd + K)
    // ------------------------------------------------------------------
    const palette = {
        el: null, input: null, list: null, items: [], active: 0, websites: null, loading: null, lastFocus: null,

        loadWebsites: function () {
            if (palette.websites || palette.loading) return palette.loading;
            palette.loading = SW.api('api/search/websites.php', { global: true }).then(function (res) {
                palette.websites = (res.data || []).map(function (w) {
                    return {
                        label: w.name, sub: w.domain + (w.client ? ' · ' + w.client : ''), href: SW.url('admin/website-details.php', { id: w.id }),
                        group: 'Websites', severity: w.severity, text: (w.name + ' ' + w.domain + ' ' + (w.client || '')).toLowerCase(),
                    };
                });
            }).catch(function () { palette.websites = []; }).finally(function () { palette.loading = null; palette.render(); });
            return palette.loading;
        },

        open: function () {
            palette.el = palette.el || document.getElementById('swPalette');
            if (!palette.el || !palette.el.hidden) return;
            palette.input = document.getElementById('swPaletteInput');
            palette.list = document.getElementById('swPaletteList');
            palette.lastFocus = document.activeElement;
            palette.el.hidden = false;
            document.documentElement.classList.add('palette-open');
            palette.input.value = '';
            palette.active = 0;
            palette.render();
            palette.loadWebsites();
            setTimeout(function () { palette.input.focus(); }, 0);
        },

        close: function () {
            if (!palette.el || palette.el.hidden) return;
            palette.el.hidden = true;
            document.documentElement.classList.remove('palette-open');
            if (palette.lastFocus && palette.lastFocus.focus && document.contains(palette.lastFocus)) palette.lastFocus.focus();
        },

        match: function (text, terms) {
            for (let i = 0; i < terms.length; i++) { if (text.indexOf(terms[i]) === -1) return false; }
            return true;
        },

        render: function () {
            if (!palette.list || !palette.input) return;
            const q = (palette.input.value || '').trim().toLowerCase();
            const terms = q.split(/\s+/).filter(Boolean);
            const pages = (config.palette || []).map(function (p) { return Object.assign({ text: p.label.toLowerCase() }, p); });
            let sites = palette.websites || [];
            let items;
            if (!terms.length) {
                // Nothing typed yet: websites that need attention first, then the pages.
                const attention = sites.filter(function (w) { return w.severity === 'down' || w.severity === 'warning'; }).slice(0, 5)
                    .map(function (w) { return Object.assign({}, w, { group: 'Needs attention' }); });
                items = attention.concat(pages.filter(function (p) { return p.group !== 'Account'; }));
            } else {
                const score = function (it) { return it.text.indexOf(terms[0]) === 0 ? 0 : 1; };
                sites = sites.filter(function (w) { return palette.match(w.text, terms); }).sort(function (a, b) { return score(a) - score(b); }).slice(0, 8);
                items = sites.concat(pages.filter(function (p) { return palette.match(p.text, terms); }));
            }
            palette.items = items;
            if (palette.active >= items.length) palette.active = Math.max(0, items.length - 1);
            if (!items.length) {
                palette.list.innerHTML = '<li class="sw-palette-empty">' + (palette.loading ? 'Loading websites…' : 'Nothing matches “' + SW.escape(q) + '”.') + '</li>';
                palette.input.removeAttribute('aria-activedescendant');
                return;
            }
            let html = '', group = null;
            items.forEach(function (it, i) {
                if (it.group !== group) { html += '<li class="sw-palette-group" role="presentation">' + SW.escape(it.group) + '</li>'; group = it.group; }
                const lead = it.severity !== undefined
                    ? '<span class="sw-status-dot sev-' + SW.escape(it.severity || 'neutral') + '" aria-hidden="true"></span>'
                    : '<i class="bi ' + SW.escape(it.icon || 'bi-arrow-right') + '" aria-hidden="true"></i>';
                html += '<li role="option" id="swPal' + i + '" class="sw-palette-item' + (i === palette.active ? ' active' : '') + '" data-i="' + i + '" aria-selected="' + (i === palette.active) + '">' +
                    lead + '<span class="min-w-0"><span class="l">' + SW.escape(it.label) + '</span>' + (it.sub ? '<span class="s">' + SW.escape(it.sub) + '</span>' : '') + '</span>' +
                    (it.shortcut ? '<kbd class="sw-kbd ms-auto">' + SW.escape(it.shortcut) + '</kbd>' : '') + '</li>';
            });
            palette.list.innerHTML = html;
            palette.input.setAttribute('aria-activedescendant', 'swPal' + palette.active);
        },

        move: function (d) {
            if (!palette.items.length) return;
            palette.active = (palette.active + d + palette.items.length) % palette.items.length;
            palette.render();
            const el = document.getElementById('swPal' + palette.active);
            if (el) el.scrollIntoView({ block: 'nearest' });
        },

        choose: function (i) {
            const it = palette.items[i];
            if (!it) return;
            palette.close();
            SW.navigate(it.href);
        },

        init: function () {
            const el = document.getElementById('swPalette');
            if (!el) return;
            const input = document.getElementById('swPaletteInput');
            nativeAdd.call(input, 'input', function () { palette.active = 0; palette.render(); });
            nativeAdd.call(input, 'keydown', function (ev) {
                if (ev.key === 'ArrowDown') { ev.preventDefault(); palette.move(1); }
                else if (ev.key === 'ArrowUp') { ev.preventDefault(); palette.move(-1); }
                else if (ev.key === 'Enter') { ev.preventDefault(); palette.choose(palette.active); }
                else if (ev.key === 'Escape') { ev.preventDefault(); palette.close(); }
                else if (ev.key === 'Tab') { ev.preventDefault(); palette.move(ev.shiftKey ? -1 : 1); }
            });
            nativeAdd.call(el, 'click', function (ev) {
                if (ev.target.closest('[data-palette-close]')) { palette.close(); return; }
                const item = ev.target.closest('.sw-palette-item');
                if (item) palette.choose(parseInt(item.getAttribute('data-i'), 10));
            });
            nativeAdd.call(el, 'mousemove', function (ev) {
                const item = ev.target.closest('.sw-palette-item');
                if (!item) return;
                const i = parseInt(item.getAttribute('data-i'), 10);
                if (i === palette.active) return;
                palette.active = i;
                SW.qsa('.sw-palette-item', el).forEach(function (n) { n.classList.toggle('active', n === item); n.setAttribute('aria-selected', String(n === item)); });
                input.setAttribute('aria-activedescendant', item.id);
            });
            const trigger = document.getElementById('paletteTrigger');
            if (trigger) nativeAdd.call(trigger, 'click', palette.open);
            // Websites change rarely; reload the list the next time the palette opens.
            nativeAdd.call(document, 'sw:website-changed', function () { palette.websites = null; });
        },
    };
    SW.palette = palette;

    // ------------------------------------------------------------------
    // Keyboard shortcuts
    // ------------------------------------------------------------------
    const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
    const isTyping = function (el) {
        if (!el) return false;
        const tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    };
    const shortcuts = {
        pending: null, timer: null,
        list: function () {
            const rows = [[isMac ? '⌘ K' : 'Ctrl K', 'Search websites and pages'], ['/', 'Search on this page'], ['?', 'Show this list'], ['[', 'Collapse or expand the sidebar']];
            (config.palette || []).forEach(function (p) { if (p.shortcut) rows.push([p.shortcut, p.label]); });
            return rows;
        },
        show: function () {
            const dl = document.getElementById('swShortcutList');
            if (!dl || !window.bootstrap) return;
            dl.innerHTML = shortcuts.list().map(function (r) {
                const keys = r[0].indexOf(' ') !== -1 && r[0].length <= 3
                    ? r[0].split(' ').map(function (k) { return '<kbd class="sw-kbd">' + SW.escape(k) + '</kbd>'; }).join(' <span class="then">then</span> ')
                    : '<kbd class="sw-kbd">' + SW.escape(r[0]) + '</kbd>';
                return '<dt>' + SW.escape(r[1]) + '</dt><dd>' + keys + '</dd>';
            }).join('');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('swShortcuts')).show();
        },
        handle: function (ev) {
            if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && (ev.key === 'k' || ev.key === 'K')) { ev.preventDefault(); palette.open(); return; }
            if (ev.ctrlKey || ev.metaKey || ev.altKey || isTyping(ev.target) || document.querySelector('.modal.show')) return;
            if (palette.el && !palette.el.hidden) return;
            const key = ev.key;
            if (shortcuts.pending) {
                const combo = shortcuts.pending + ' ' + key.toLowerCase();
                shortcuts.pending = null;
                clearTimeout(shortcuts.timer);
                const hit = (config.palette || []).find(function (p) { return p.shortcut === combo; });
                if (hit) { ev.preventDefault(); SW.navigate(hit.href); }
                return;
            }
            if (key === 'g') { shortcuts.pending = 'g'; shortcuts.timer = setTimeout(function () { shortcuts.pending = null; }, 1200); return; }
            if (key === '?') { ev.preventDefault(); shortcuts.show(); return; }
            if (key === '[') {
                const c = document.getElementById('sidebarCollapse');
                if (c && c.offsetParent !== null) { ev.preventDefault(); c.click(); }
                return;
            }
            if (key === '/') {
                ev.preventDefault();
                const search = document.querySelector('#mainContent input[type="search"]');
                if (search && search.offsetParent !== null) { search.focus(); search.select(); } else { palette.open(); }
                return;
            }
            const single = (config.palette || []).find(function (p) { return p.shortcut === key; });
            if (single) { ev.preventDefault(); SW.navigate(single.href); }
        },
    };

    // ------------------------------------------------------------------
    // Installed app (PWA) and desktop notifications
    // ------------------------------------------------------------------
    const pwa = {
        registration: null,
        installPrompt: null,

        init: function () {
            if ('serviceWorker' in navigator && window.isSecureContext) {
                navigator.serviceWorker.register(SW.url('sw.js'), { scope: SW.baseUrl + '/' }).then(function (reg) {
                    pwa.registration = reg;
                    document.dispatchEvent(new CustomEvent('sw:worker', { detail: reg }));
                }).catch(function (e) { console.warn('Service worker not registered', e); });
                // A notification was clicked while SiteWatch was open: go to its page in place.
                nativeAdd.call(navigator.serviceWorker, 'message', function (ev) {
                    if (ev.data && ev.data.type === 'sw-open' && ev.data.url) SW.navigate(ev.data.url);
                });
            }
            nativeAdd.call(window, 'beforeinstallprompt', function (ev) {
                ev.preventDefault();
                pwa.installPrompt = ev;
                pwa.syncInstall();
            });
            nativeAdd.call(window, 'appinstalled', function () {
                pwa.installPrompt = null;
                pwa.syncInstall();
                SW.toast('SiteWatch is installed. You can open it from your Start menu, Dock or desktop.', 'success', { delay: 7000 });
            });
            nativeAdd.call(document, 'click', function (ev) {
                if (ev.target.closest && ev.target.closest('[data-install-app]')) { ev.preventDefault(); pwa.install(); }
            });
            pwa.syncInstall();
        },

        standalone: function () {
            return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
        },

        /** Safari (macOS 14+) installs with File › Add to Dock; it never fires beforeinstallprompt. */
        safari: function () {
            const ua = navigator.userAgent;
            return /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR/.test(ua) && /Macintosh/.test(ua);
        },

        canInstall: function () { return !pwa.standalone() && (!!pwa.installPrompt || pwa.safari()); },

        syncInstall: function () {
            SW.qsa('[data-install-app]').forEach(function (el) { el.hidden = !pwa.canInstall(); });
            SW.qsa('[data-installed-note]').forEach(function (el) { el.hidden = !pwa.standalone(); });
        },

        install: async function () {
            if (pwa.installPrompt) {
                pwa.installPrompt.prompt();
                try { await pwa.installPrompt.userChoice; } catch (e) { /* ignore */ }
                pwa.installPrompt = null;
                pwa.syncInstall();
            } else if (pwa.safari()) {
                SW.toast('In Safari, choose File › Add to Dock to install SiteWatch as an app.', 'info', { delay: 9000, title: 'Install SiteWatch' });
            } else {
                SW.toast('Use the install icon in your browser\'s address bar, or its menu › Install SiteWatch.', 'info', { delay: 9000, title: 'Install SiteWatch' });
            }
        },
    };
    SW.pwa = pwa;

    /**
     * Desktop notifications while SiteWatch is open (any tab or the installed app). Devices with Web Push turned on
     * get the same events from the server even when SiteWatch is closed, so they skip these.
     */
    SW.notify = {
        enabled: function () {
            return 'Notification' in window && Notification.permission === 'granted' && SW.storage.get('sw-notify', false) === true && SW.storage.get('sw-push', false) !== true;
        },
        show: function (title, body, url, tag) {
            if (!SW.notify.enabled()) return false;
            const options = { body: body || '', tag: tag || undefined, icon: SW.url('assets/images/icons/icon-192.png'), badge: SW.url('assets/images/icons/badge-96.png'), data: { url: url } };
            try {
                if (pwa.registration && pwa.registration.showNotification) {
                    pwa.registration.showNotification(title, options);
                } else {
                    const n = new Notification(title, options);
                    n.onclick = function () { window.focus(); if (url) SW.navigate(url); n.close(); };
                }
                return true;
            } catch (e) { return false; }
        },
        handleChanges: function (changes) {
            (changes || []).forEach(function (c) {
                const shown = document.hidden || !document.hasFocus() ? SW.notify.show(c.title, c.body, c.url, 'incident-' + c.id + '-' + c.kind) : false;
                if (!shown) SW.toast(c.body, c.kind === 'recovery' ? 'success' : 'danger', { title: c.title, delay: 8000 });
            });
        },
    };

    // ------------------------------------------------------------------
    // Page boot — runs on the first load and after every in-app navigation
    // ------------------------------------------------------------------
    const refreshTimes = function () {
        SW.qsa('[data-timeago]').forEach(function (el) { const v = el.getAttribute('data-timeago'); if (v) el.textContent = SW.fmt.timeAgo(v); });
    };

    bootPage = function () {
        booting = true;
        try {
            refreshTimes();
            SW.qsa('[data-flash]').forEach(function (el) { SW.toast(el.getAttribute('data-flash'), el.getAttribute('data-flash-type') || 'success'); el.remove(); });
            document.dispatchEvent(new CustomEvent('sw:ready'));
        } finally {
            booting = false;
        }
    };

    const hideSplash = function () {
        const splash = document.getElementById('swSplash');
        if (!splash) return;
        if (document.documentElement.classList.contains('no-splash')) { splash.remove(); return; }
        // Let the mark finish drawing (about 0.7 s) but never hold a ready page longer than that.
        const wait = Math.max(0, 700 - performance.now());
        setTimeout(function () {
            splash.classList.add('is-done');
            setTimeout(function () { splash.remove(); }, 320);
        }, wait);
    };

    SW.core(function () {
        document.addEventListener('DOMContentLoaded', function () {
            SW.core(function () {
                SW.theme.apply(SW.theme.current());
                const themeBtn = document.getElementById('themeToggle');
                if (themeBtn) themeBtn.addEventListener('click', SW.theme.toggle);
                SW.qsa('.auth-theme-toggle').forEach(function (b) { b.addEventListener('click', SW.theme.toggle); });

                const html = document.documentElement;
                const toggle = document.getElementById('sidebarToggle');
                const collapse = document.getElementById('sidebarCollapse');
                const backdrop = document.getElementById('sidebarBackdrop');
                const sidebarEl = document.getElementById('sidebar');
                closeSidebar = function (returnFocus) {
                    if (!html.classList.contains('sidebar-open')) return;
                    html.classList.remove('sidebar-open');
                    if (toggle) {
                        toggle.setAttribute('aria-expanded', 'false');
                        if (returnFocus) toggle.focus();
                    }
                };
                const openSidebar = function () {
                    html.classList.add('sidebar-open');
                    if (toggle) toggle.setAttribute('aria-expanded', 'true');
                    const firstLink = sidebarEl && sidebarEl.querySelector('.sw-nav-link');
                    if (firstLink) firstLink.focus();
                };
                if (toggle) {
                    toggle.setAttribute('aria-controls', 'sidebar');
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.addEventListener('click', function () {
                        if (html.classList.contains('sidebar-open')) { closeSidebar(true); } else { openSidebar(); }
                    });
                }
                if (backdrop) backdrop.addEventListener('click', function () { closeSidebar(true); });
                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') { closeSidebar(true); palette.close(); SW.panel.close(); }
                });
                document.addEventListener('keydown', shortcuts.handle);
                // Keep focus inside the mobile navigation while it is open.
                document.addEventListener('focusin', function (event) {
                    if (!html.classList.contains('sidebar-open') || !sidebarEl) return;
                    if (sidebarEl.contains(event.target) || (toggle && toggle.contains(event.target))) return;
                    const firstLink = sidebarEl.querySelector('.sw-nav-link');
                    if (firstLink) firstLink.focus();
                });
                // Delegated, so it keeps working on pages swapped in later.
                const densityLabel = function () {
                    SW.qsa('[data-density-label]').forEach(function (l) { l.textContent = html.classList.contains('density-compact') ? 'Comfortable tables' : 'Compact tables'; });
                };
                document.addEventListener('click', function (ev) {
                    const t = ev.target.closest ? ev.target : null;
                    if (!t) return;
                    if (t.closest('#printReport')) window.print();
                    if (t.closest('[data-panel-close]') || t.classList.contains('sw-panel-backdrop')) SW.panel.close();
                    if (t.closest('[data-open-shortcuts]')) shortcuts.show();
                    if (t.closest('[data-toggle-density]')) {
                        const compact = html.classList.toggle('density-compact');
                        try { localStorage.setItem('sw-density', compact ? 'compact' : 'comfortable'); } catch (e) { /* ignore */ }
                        densityLabel();
                    }
                });
                densityLabel();
                SW.qsa('[data-mod-key]').forEach(function (k) { k.textContent = isMac ? '⌘ K' : 'Ctrl K'; });
                if (collapse) collapse.addEventListener('click', function () {
                    html.classList.toggle('sidebar-collapsed');
                    try { localStorage.setItem('sw-sidebar', html.classList.contains('sidebar-collapsed') ? 'collapsed' : 'expanded'); } catch (e) { /* ignore */ }
                });

                // Sidebar tooltips only when the sidebar is collapsed on desktop.
                SW.qsa('.sw-sidebar [data-bs-toggle="tooltip"]').forEach(function (el) {
                    new bootstrap.Tooltip(el, { container: 'body', trigger: 'hover', delay: { show: 400, hide: 0 } });
                    el.addEventListener('show.bs.tooltip', function (ev) {
                        const isCollapsed = html.classList.contains('sidebar-collapsed') && window.innerWidth >= 992;
                        if (!isCollapsed) ev.preventDefault();
                    });
                });
                SW.tooltips(document.querySelector('.sw-topbar'));

                // Sticky table action cells each form their own stacking context, so an open row menu would
                // otherwise be painted underneath the action cells of the rows below it.
                document.addEventListener('show.bs.dropdown', function (ev) {
                    const cell = ev.target && ev.target.closest ? ev.target.closest('td, th') : null;
                    if (cell) cell.classList.add('menu-open');
                });
                document.addEventListener('hidden.bs.dropdown', function (ev) {
                    const cell = ev.target && ev.target.closest ? ev.target.closest('td, th') : null;
                    if (cell) cell.classList.remove('menu-open');
                });

                // Favicon fallback (CSP forbids inline onerror handlers)
                document.addEventListener('error', function (ev) {
                    const img = ev.target;
                    if (img && img.tagName === 'IMG' && img.classList.contains('sw-favicon')) {
                        const span = document.createElement('span');
                        span.className = 'sw-favicon-fallback' + (img.classList.contains('lg') ? ' lg' : '');
                        span.innerHTML = '<i class="bi bi-globe2"></i>';
                        img.replaceWith(span);
                    }
                }, true);

                setInterval(refreshTimes, 20000);

                // Engine state and incident count on every admin page (light request).
                if (document.getElementById('engineStatus')) {
                    let since = null;
                    SW.poll(async function () {
                        const res = await SW.api('api/monitoring/status.php', { global: true, query: { since: since } });
                        SW.updateEngine(res.data.engine);
                        SW.updateIncidentCount(res.data.open_incidents);
                        // The first answer only sets the starting point; later ones report what changed since.
                        if (since !== null) SW.notify.handleChanges(res.data.changes);
                        since = res.data.server_time;
                        document.dispatchEvent(new CustomEvent('sw:status', { detail: res.data }));
                    }, Math.max(20000, (config.refresh || 30) * 1000), { immediate: true });
                    SW.updateIncidentCount(config.openIncidents || 0);
                }

                router.init();
                palette.init();
                pwa.init();
                document.dispatchEvent(new CustomEvent('sw:core'));
            });

            bootPage();
            hideSplash();
        });
    });

    window.SW = SW;
    // Page scripts that follow this file register their start-up code now; it belongs to the page.
    booting = true;
})();
