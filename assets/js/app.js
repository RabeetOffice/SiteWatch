/* ==========================================================================
   SiteWatch — core front-end runtime (vanilla JS, no jQuery)
   Exposes window.SW with: api, toast, confirm, theme, fmt, badge, favicon, poll, escape, ...
   ========================================================================== */
(function () {
    'use strict';

    const cfgEl = document.getElementById('sw-config');
    const config = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : {};
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

        let response;
        try {
            response = await fetch(url, init);
        } catch (err) {
            if (err && err.name === 'AbortError') throw err;
            const e = new Error('Network error. Please check your connection and try again.');
            e.network = true;
            throw e;
        }
        let payload = null;
        try { payload = await response.json(); } catch (e) { payload = null; }

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
            grid: dark ? '#1F2937' : '#EEF2F7',
            text: dark ? '#94A3B8' : '#64748B',
            primary: dark ? '#8B7CF6' : '#6C5CE7',
            primarySoft: dark ? 'rgba(139,124,246,0.18)' : 'rgba(108,92,231,0.12)',
            success: dark ? '#22C55E' : '#16A34A',
            warning: dark ? '#FBBF24' : '#F59E0B',
            danger: dark ? '#F87171' : '#DC2626',
            info: dark ? '#60A5FA' : '#2563EB',
            neutral: dark ? '#475569' : '#CBD5E1',
            tooltipBg: dark ? '#E5E7EB' : '#111827',
            tooltipText: dark ? '#0F172A' : '#FFFFFF',
        };
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
        if (first) first.focus();
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
            if (!indicator) return;
            indicator.classList.toggle('paused', !active);
            const txt = indicator.querySelector('.txt');
            if (txt) txt.textContent = active ? 'Live' : 'Paused';
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
        document.addEventListener('visibilitychange', function () { if (!document.hidden && !stopped) { clearTimeout(timer); tick(); } });
        if (opts.immediate) { tick(); } else { schedule(); }
        return { stop: function () { stopped = true; clearTimeout(timer); }, now: function () { clearTimeout(timer); tick(); } };
    };

    // ------------------------------------------------------------------
    // Engine status widget
    // ------------------------------------------------------------------
    SW.updateEngine = function (engine) {
        const el = document.getElementById('engineStatus');
        if (!el || !engine) return;
        el.className = 'sw-engine state-' + engine.state;
        const label = el.querySelector('[data-engine-label]');
        const meta = el.querySelector('[data-engine-meta]');
        if (label) label.textContent = engine.label;
        if (meta) meta.textContent = 'Last run: ' + (engine.last_run_ago || 'never');
        el.setAttribute('data-bs-original-title', engine.message || ('Last run ' + (engine.last_run_ago || 'never')));
    };
    SW.updateIncidentCount = function (count) {
        const el = document.getElementById('navIncidentCount');
        if (el) el.textContent = count > 0 ? String(count) : '';
    };

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        SW.theme.apply(SW.theme.current());
        const themeBtn = document.getElementById('themeToggle');
        if (themeBtn) themeBtn.addEventListener('click', SW.theme.toggle);
        SW.qsa('.auth-theme-toggle').forEach(function (b) { b.addEventListener('click', SW.theme.toggle); });

        const html = document.documentElement;
        const toggle = document.getElementById('sidebarToggle');
        const collapse = document.getElementById('sidebarCollapse');
        const backdrop = document.getElementById('sidebarBackdrop');
        if (toggle) toggle.addEventListener('click', function () { html.classList.toggle('sidebar-open'); });
        if (backdrop) backdrop.addEventListener('click', function () { html.classList.remove('sidebar-open'); });
        if (collapse) collapse.addEventListener('click', function () {
            html.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('sw-sidebar', html.classList.contains('sidebar-collapsed') ? 'collapsed' : 'expanded'); } catch (e) { /* ignore */ }
        });
        SW.qsa('.sw-nav-link').forEach(function (a) { a.addEventListener('click', function () { html.classList.remove('sidebar-open'); }); });

        // Sidebar tooltips only when collapsed on desktop
        SW.qsa('.sw-sidebar [data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el, { container: 'body', trigger: 'hover', delay: { show: 400, hide: 0 } });
            el.addEventListener('show.bs.tooltip', function (ev) {
                const isCollapsed = html.classList.contains('sidebar-collapsed') && window.innerWidth >= 992;
                if (!isCollapsed && !el.classList.contains('sw-engine')) ev.preventDefault();
            });
        });
        SW.tooltips(document.querySelector('.sw-topbar'));

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

        // Relative time refresh
        const refreshTimes = function () {
            SW.qsa('[data-timeago]').forEach(function (el) { const v = el.getAttribute('data-timeago'); if (v) el.textContent = SW.fmt.timeAgo(v); });
        };
        refreshTimes();
        setInterval(refreshTimes, 20000);

        // Engine + incident count polling on every admin page (light request)
        if (document.getElementById('engineStatus')) {
            SW.poll(async function () {
                const res = await SW.api('api/monitoring/status.php');
                SW.updateEngine(res.data.engine);
                SW.updateIncidentCount(res.data.open_incidents);
            }, Math.max(20000, (config.refresh || 30) * 1000));
        }

        // Flash messages rendered server-side
        SW.qsa('[data-flash]').forEach(function (el) { SW.toast(el.getAttribute('data-flash'), el.getAttribute('data-flash-type') || 'success'); el.remove(); });

        document.dispatchEvent(new CustomEvent('sw:ready'));
    });

    window.SW = SW;
})();
