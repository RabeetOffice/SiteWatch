/* SiteWatch — roles & permissions */
(function () {
    'use strict';

    const esc = SW.escape;
    const state = { roles: [], catalogue: [] };
    let modal = null;

    function label(key) {
        for (let g = 0; g < state.catalogue.length; g++) {
            const p = state.catalogue[g].permissions.find(function (x) { return x.key === key; });
            if (p) return p.label;
        }
        return key;
    }

    function accessCell(r) {
        if (r.full_access) return SW.pill('Full access', 'primary', 'bi-shield-check');
        const total = SW.page.totalPermissions || 0;
        if (!r.permissions.length) return '<span class="text-muted fs-13">Dashboard and websites only</span>';
        return '<details class="detail-toggle mt-0"><summary>' + r.permissions.length + ' of ' + total + ' permissions</summary>' +
            '<div class="detail-body"><span class="chip-list">' + r.permissions.map(function (k) { return SW.pill(label(k), 'neutral'); }).join('') + '</span></div></details>';
    }

    function rowHtml(r) {
        const users = r.user_count
            ? (SW.can('users.manage') ? '<a href="' + SW.url('admin/users.php', { role: r.id }) + '">' + r.user_count + '</a>' : String(r.user_count))
            : '<span class="text-faint">0</span>';
        let actions;
        if (r.is_system) {
            actions = '<span class="fs-12 text-muted" data-bs-toggle="tooltip" title="The Administrator role always has every permission"><i class="bi bi-lock" aria-hidden="true"></i> Locked</span>';
        } else if (!r.can_edit) {
            actions = '<span class="fs-12 text-muted" data-bs-toggle="tooltip" title="This role has permissions you do not have">No access</span>';
        } else {
            const deleteBtn = r.can_delete
                ? '<li><button type="button" class="dropdown-item text-danger" data-role-action="delete" data-id="' + r.id + '"><i class="bi bi-trash"></i> Delete</button></li>'
                : '<li><span class="dropdown-item disabled text-muted" aria-disabled="true"><i class="bi bi-trash"></i> Delete — assigned to ' + r.user_count + ' user' + (r.user_count === 1 ? '' : 's') + '</span></li>';
            actions = '<button type="button" class="btn btn-sm btn-light" data-role-action="edit" data-id="' + r.id + '">Edit</button>' +
                '<div class="dropdown"><button type="button" class="btn-icon btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for ' + esc(r.name) + '"><i class="bi bi-three-dots" aria-hidden="true"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item" data-role-action="duplicate" data-id="' + r.id + '"><i class="bi bi-copy"></i> Duplicate</button></li>' +
                '<li><hr class="dropdown-divider"></li>' + deleteBtn + '</ul></div>';
        }
        return '<tr><td><div class="fw-600 d-flex align-items-center gap-2 flex-wrap">' + esc(r.name) + (r.is_system ? SW.pill('Built-in', 'neutral') : '') + '</div>' +
            (r.description ? '<div class="fs-12 text-muted">' + esc(r.description) + '</div>' : '') + '</td>' +
            '<td>' + accessCell(r) + '</td>' +
            '<td class="num">' + users + '</td>' +
            '<td class="hide-mobile nowrap fs-13">' + esc(r.updated_label) + '</td>' +
            '<td class="actions"><div class="row-actions">' + actions + '</div></td></tr>';
    }

    function renderMatrix() {
        const table = document.getElementById('permMatrix');
        let html = '<thead><tr><th scope="col">Permission</th>' + state.roles.map(function (r) { return '<th scope="col" class="role-col">' + esc(r.name) + '</th>'; }).join('') + '</tr></thead><tbody>';
        html += '<tr class="group-row"><td colspan="' + (state.roles.length + 1) + '">Everyone</td></tr>' +
            '<tr><td>Dashboard, websites and own profile</td>' + state.roles.map(function () { return '<td class="cell"><i class="bi bi-check-lg perm-yes" aria-label="Yes"></i></td>'; }).join('') + '</tr>';
        state.catalogue.forEach(function (group) {
            html += '<tr class="group-row"><td colspan="' + (state.roles.length + 1) + '">' + esc(group.group) + '</td></tr>';
            group.permissions.forEach(function (p) {
                html += '<tr><td><span data-bs-toggle="tooltip" title="' + esc(p.description) + '">' + esc(p.label) + '</span></td>' + state.roles.map(function (r) {
                    const yes = r.permissions.indexOf(p.key) !== -1;
                    return '<td class="cell">' + (yes ? '<i class="bi bi-check-lg perm-yes" aria-label="Yes"></i>' : '<span class="perm-no" aria-label="No">—</span>') + '</td>';
                }).join('') + '</tr>';
            });
        });
        table.innerHTML = html + '</tbody>';
        SW.tooltips(table);
    }

    function render() {
        const body = document.getElementById('rolesBody');
        body.innerHTML = state.roles.map(rowHtml).join('');
        const custom = state.roles.filter(function (r) { return !r.is_system; }).length;
        document.getElementById('rolesSummary').textContent = state.roles.length + ' roles · ' + custom + ' editable';
        SW.tooltips(body);
        SW.dropdowns(body);
        renderMatrix();
    }

    async function load() {
        const res = await SW.api('api/roles/list.php');
        state.roles = res.data.roles;
        state.catalogue = res.data.catalogue;
        render();
    }

    // ------------------------------------------------------------------
    // Editor
    // ------------------------------------------------------------------
    function checkboxes() { return SW.qsa('#rolePermissions input[type="checkbox"][data-perm]'); }

    function updateCount() {
        const selected = checkboxes().filter(function (c) { return c.checked; }).length;
        document.getElementById('rolePermissionCount').textContent = selected + ' of ' + checkboxes().length + ' permissions selected';
        SW.qsa('#rolePermissions [data-group-toggle]').forEach(function (btn) {
            const boxes = SW.qsa('input[data-perm]:not(:disabled)', btn.closest('.perm-group'));
            btn.textContent = boxes.length && boxes.every(function (c) { return c.checked; }) ? 'Clear group' : 'Select group';
            btn.disabled = !boxes.length;
        });
    }

    function renderEditor(selected) {
        document.getElementById('rolePermissions').innerHTML = state.catalogue.map(function (group, gi) {
            return '<div class="perm-group"><div class="perm-group-head"><h3>' + esc(group.group) + '</h3>' +
                '<button type="button" class="btn btn-sm btn-ghost" data-group-toggle="' + gi + '">Select group</button></div>' +
                '<div class="option-grid">' + group.permissions.map(function (p) {
                    const allowed = SW.can(p.key);
                    return '<label class="option-card' + (allowed ? '' : ' is-disabled') + '"' + (allowed ? '' : ' title="You do not have this permission yourself"') + '>' +
                        '<input class="form-check-input" type="checkbox" data-perm="' + esc(p.key) + '"' + (selected.indexOf(p.key) !== -1 ? ' checked' : '') + (allowed ? '' : ' disabled') + '>' +
                        '<span><span class="t">' + esc(p.label) + '</span><span class="d">' + esc(p.description) + '</span></span></label>';
                }).join('') + '</div></div>';
        }).join('');
        updateCount();
    }

    function openModal(role, duplicate) {
        const form = document.getElementById('roleForm');
        form.reset();
        SW.showErrors(form, {});
        const isEdit = !!role && !duplicate;
        document.getElementById('roleModalTitle').textContent = isEdit ? 'Edit ' + role.name : (duplicate ? 'Duplicate ' + role.name : 'Create role');
        form.querySelector('[data-submit-label]').textContent = isEdit ? 'Save changes' : 'Create role';
        form.querySelector('[name="id"]').value = isEdit ? role.id : '';
        form.querySelector('[name="name"]').value = role ? (duplicate ? role.name + ' copy' : role.name) : '';
        form.querySelector('[name="description"]').value = role ? role.description : '';
        renderEditor(role ? role.permissions : []);
        modal.show();
        setTimeout(function () { form.querySelector('[name="name"]').focus(); }, 200);
    }

    async function submit(ev) {
        ev.preventDefault();
        const form = ev.currentTarget;
        const btn = form.querySelector('[type="submit"]');
        SW.showErrors(form, {});
        SW.setLoading(btn, true, 'Saving…');
        try {
            const res = await SW.api('api/roles/save.php', {
                method: 'POST',
                body: {
                    id: form.querySelector('[name="id"]').value,
                    name: form.querySelector('[name="name"]').value,
                    description: form.querySelector('[name="description"]').value,
                    permissions: checkboxes().filter(function (c) { return c.checked; }).map(function (c) { return c.getAttribute('data-perm'); }),
                },
            });
            modal.hide();
            SW.toast(res.message, 'success');
            await load();
        } catch (e) {
            SW.showErrors(form, e.errors || {});
            SW.toast(e.message, 'danger');
        } finally {
            SW.setLoading(btn, false);
        }
    }

    async function rowAction(action, id) {
        const role = state.roles.find(function (r) { return r.id === id; });
        if (!role) return;
        if (action === 'edit') { openModal(role, false); return; }
        if (action === 'duplicate') { openModal(role, true); return; }
        if (action === 'delete') {
            const ok = await SW.confirm({ title: 'Delete the ' + role.name + ' role?', message: 'The role is removed permanently. No users are assigned to it.', confirmText: 'Delete role' });
            if (!ok) return;
            try {
                const res = await SW.api('api/roles/delete.php', { method: 'POST', body: { id: role.id } });
                SW.toast(res.message, 'success');
                await load();
            } catch (e) { SW.toast(e.message, 'danger'); }
        }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('rolesBody')) return;
        modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('roleModal'));
        document.getElementById('btnAddRole').addEventListener('click', function () { openModal(null, false); });
        document.getElementById('roleForm').addEventListener('submit', submit);
        const editor = document.getElementById('rolePermissions');
        editor.addEventListener('change', updateCount);
        editor.addEventListener('click', function (ev) {
            const toggle = ev.target.closest('[data-group-toggle]');
            if (!toggle) return;
            const boxes = SW.qsa('input[data-perm]:not(:disabled)', toggle.closest('.perm-group'));
            const check = !boxes.every(function (c) { return c.checked; });
            boxes.forEach(function (c) { c.checked = check; });
            updateCount();
        });
        document.addEventListener('click', function (ev) {
            const el = ev.target.closest('[data-role-action]');
            if (el) { ev.preventDefault(); rowAction(el.getAttribute('data-role-action'), parseInt(el.getAttribute('data-id'), 10)); }
        });
        document.getElementById('rolesBody').innerHTML = SW.skeletonRows(5, 3);
        load().catch(function (e) {
            document.getElementById('rolesBody').innerHTML = '<tr><td colspan="5">' + SW.emptyState('bi-wifi-off', 'Unable to load roles', e.message) + '</td></tr>';
        });
    });
})();
