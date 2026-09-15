/* SiteWatch — user management */
(function () {
    'use strict';

    const esc = SW.escape;
    const state = { users: [], roles: [], q: '', role: '', status: '' };
    let modal = null;

    function roleById(id) {
        return state.roles.find(function (r) { return r.id === Number(id); }) || null;
    }

    function rowHtml(u) {
        const items = [];
        if (u.can_edit && !u.is_self) {
            items.push('<li><button type="button" class="dropdown-item" data-user-action="' + (u.is_active ? 'deactivate' : 'activate') + '" data-id="' + u.id + '">' +
                '<i class="bi ' + (u.is_active ? 'bi-person-dash' : 'bi-person-check') + '"></i> ' + (u.is_active ? 'Deactivate' : 'Reactivate') + '</button></li>');
        }
        if (u.can_delete) {
            items.push('<li><hr class="dropdown-divider"></li><li><button type="button" class="dropdown-item text-danger" data-user-action="delete" data-id="' + u.id + '"><i class="bi bi-trash"></i> Delete</button></li>');
        }
        let actions = '';
        if (u.can_edit) actions += '<button type="button" class="btn btn-sm btn-light" data-user-action="edit" data-id="' + u.id + '">Edit</button>';
        if (items.length) {
            actions += '<div class="dropdown"><button type="button" class="btn-icon btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for ' + esc(u.name) + '"><i class="bi bi-three-dots" aria-hidden="true"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end">' + items.join('') + '</ul></div>';
        }
        if (!u.can_edit) actions = '<span class="fs-12 text-muted" data-bs-toggle="tooltip" title="This user has more access than you">No access</span>';

        const lastLogin = u.last_login_at
            ? SW.timeAgoEl(u.last_login_at) + (u.last_login_ip ? '<div class="fs-12 text-muted mono">' + esc(u.last_login_ip) + '</div>' : '')
            : '<span class="text-faint">Never</span>';

        return '<tr data-user="' + u.id + '"' + (u.is_active ? '' : ' class="is-inactive"') + '>' +
            '<td><div class="user-cell"><span class="sw-avatar" aria-hidden="true">' + esc(u.initials) + '</span><div class="min-w-0">' +
            '<div class="n"><span class="truncate">' + esc(u.name) + '</span>' + (u.is_self ? SW.pill('You', 'primary') : '') + '</div>' +
            '<div class="e truncate">' + esc(u.email) + '</div></div></div></td>' +
            '<td>' + SW.pill(u.role_name, u.full_access ? 'primary' : 'neutral', u.full_access ? 'bi-shield-check' : null) + '</td>' +
            '<td>' + (u.is_active ? SW.badge('active', 'Active', 'ok') : SW.badge('inactive', 'Deactivated', 'neutral')) + '</td>' +
            '<td class="hide-mobile nowrap fs-13">' + lastLogin + '</td>' +
            '<td class="hide-mobile nowrap fs-13">' + esc(u.created_label) + '</td>' +
            '<td class="actions"><div class="row-actions">' + actions + '</div></td></tr>';
    }

    function render() {
        const body = document.getElementById('usersBody');
        const q = state.q.toLowerCase();
        const rows = state.users.filter(function (u) {
            if (q && (u.name + ' ' + u.email).toLowerCase().indexOf(q) === -1) return false;
            if (state.role && u.role_id !== Number(state.role)) return false;
            if (state.status === 'active' && !u.is_active) return false;
            if (state.status === 'inactive' && u.is_active) return false;
            return true;
        });
        const active = state.users.filter(function (u) { return u.is_active; }).length;
        document.getElementById('usersSummary').textContent = state.users.length + (state.users.length === 1 ? ' user' : ' users') + ' · ' + active + ' active';
        body.innerHTML = rows.length
            ? rows.map(rowHtml).join('')
            : '<tr><td colspan="6">' + SW.emptyState('bi-people', state.users.length ? 'No users match these filters.' : 'No users yet.', state.users.length ? 'Try a different search or filter.' : '') + '</td></tr>';
        SW.tooltips(body);
        SW.dropdowns(body);
    }

    function renderRoleFilter() {
        const sel = document.getElementById('userRoleFilter');
        const current = state.role;
        sel.innerHTML = '<option value="">All roles</option>' + state.roles.map(function (r) {
            return '<option value="' + r.id + '">' + esc(r.name) + ' (' + r.user_count + ')</option>';
        }).join('');
        sel.value = roleById(current) ? String(current) : '';
    }

    async function load() {
        const res = await SW.api('api/users/list.php');
        state.users = res.data.users;
        state.roles = res.data.roles;
        renderRoleFilter();
        render();
    }

    // ------------------------------------------------------------------
    // Add / edit
    // ------------------------------------------------------------------
    function field(name) { return document.querySelector('#userForm [name="' + name + '"]'); }

    function updateRoleHelp() {
        const role = roleById(field('role_id').value);
        const help = document.getElementById('u-role-help');
        if (!role) { help.textContent = ''; return; }
        help.textContent = (role.full_access ? 'Full access to everything. ' : role.permissions.length + ' permission' + (role.permissions.length === 1 ? '' : 's') + '. ') + role.description;
    }

    function openModal(user) {
        const form = document.getElementById('userForm');
        form.reset();
        SW.showErrors(form, {});
        const isEdit = !!user;
        const self = isEdit && user.is_self;

        document.getElementById('userModalTitle').textContent = isEdit ? 'Edit ' + user.name : 'Add user';
        form.querySelector('[data-submit-label]').textContent = isEdit ? 'Save changes' : 'Add user';
        field('id').value = isEdit ? user.id : '';
        field('name').value = isEdit ? user.name : '';
        field('email').value = isEdit ? user.email : '';

        const roleSelect = field('role_id');
        const defaultRole = state.roles.find(function (r) { return r.slug === 'viewer' && r.assignable; }) || state.roles.find(function (r) { return r.assignable; });
        roleSelect.innerHTML = state.roles.map(function (r) {
            const disabled = !r.assignable && !(isEdit && r.id === user.role_id);
            return '<option value="' + r.id + '"' + (disabled ? ' disabled' : '') + '>' + esc(r.name) + (disabled ? ' — more access than you' : '') + '</option>';
        }).join('');
        roleSelect.value = String(isEdit ? user.role_id : (defaultRole ? defaultRole.id : ''));
        roleSelect.disabled = self;
        updateRoleHelp();
        if (self) document.getElementById('u-role-help').textContent = 'You cannot change your own role. Ask another administrator.';

        const password = field('password');
        password.type = 'password';
        password.required = !isEdit;
        password.placeholder = isEdit ? 'Leave blank to keep the current password' : '';
        document.getElementById('u-password-label').textContent = isEdit ? 'New password (optional)' : 'Password';
        document.getElementById('u-password-help').textContent = isEdit
            ? 'Setting a new password signs this user out of every session.'
            : 'At least 10 characters. Share it with the user securely — they can change it from their profile.';

        const active = field('is_active');
        active.checked = isEdit ? user.is_active : true;
        active.disabled = self;

        modal.show();
        setTimeout(function () { field('name').focus(); }, 200);
    }

    function generatePassword() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%*-_';
        const bytes = new Uint32Array(18);
        window.crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (b) { return chars[b % chars.length]; }).join('');
    }

    async function save(body, form) {
        const res = await SW.api('api/users/save.php', { method: 'POST', body: body });
        if (form) modal.hide();
        SW.toast(res.message, 'success');
        await load();
        return res;
    }

    async function submit(ev) {
        ev.preventDefault();
        const form = ev.currentTarget;
        const btn = form.querySelector('[type="submit"]');
        SW.showErrors(form, {});
        SW.setLoading(btn, true, 'Saving…');
        try {
            await save({
                id: field('id').value,
                name: field('name').value,
                email: field('email').value,
                role_id: field('role_id').value,
                password: field('password').value,
                is_active: field('is_active').checked ? '1' : '0',
            }, form);
        } catch (e) {
            SW.showErrors(form, e.errors || {});
            SW.toast(e.message, 'danger');
        } finally {
            SW.setLoading(btn, false);
        }
    }

    async function rowAction(action, id) {
        const user = state.users.find(function (u) { return u.id === id; });
        if (!user) return;
        if (action === 'edit') { openModal(user); return; }

        if (action === 'deactivate' || action === 'activate') {
            const activate = action === 'activate';
            if (!activate) {
                const ok = await SW.confirm({
                    title: 'Deactivate ' + user.name + '?',
                    message: 'They are signed out straight away and cannot sign in until you reactivate the account. Their activity history is kept.',
                    confirmText: 'Deactivate',
                });
                if (!ok) return;
            }
            try {
                await save({ id: user.id, name: user.name, email: user.email, role_id: user.role_id, password: '', is_active: activate ? '1' : '0' });
            } catch (e) { SW.toast(e.message, 'danger'); }
            return;
        }

        if (action === 'delete') {
            const ok = await SW.confirm({
                title: 'Delete ' + user.name + '?',
                message: 'The account is removed permanently and past activity will no longer show their name. Deactivate the account instead if you want to keep that history attributed.',
                confirmText: 'Delete user',
            });
            if (!ok) return;
            try {
                const res = await SW.api('api/users/delete.php', { method: 'POST', body: { id: user.id } });
                SW.toast(res.message, 'success');
                await load();
            } catch (e) { SW.toast(e.message, 'danger'); }
        }
    }

    document.addEventListener('sw:ready', function () {
        const page = document.getElementById('usersPage');
        if (!page) return;
        modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal'));
        if (SW.page.roleFilter) state.role = String(SW.page.roleFilter);

        document.getElementById('btnAddUser').addEventListener('click', function () { openModal(null); });
        document.getElementById('userForm').addEventListener('submit', submit);
        document.getElementById('u-role').addEventListener('change', updateRoleHelp);
        document.getElementById('u-password-generate').addEventListener('click', function () {
            const input = field('password');
            input.value = generatePassword();
            input.type = 'text';
            input.focus();
            input.select();
        });
        document.getElementById('u-password-toggle').addEventListener('click', function () {
            const input = field('password');
            input.type = input.type === 'password' ? 'text' : 'password';
            this.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password');
            this.innerHTML = '<i class="bi ' + (input.type === 'password' ? 'bi-eye' : 'bi-eye-slash') + '" aria-hidden="true"></i>';
        });

        document.getElementById('userSearch').addEventListener('input', SW.debounce(function () { state.q = this.value.trim(); render(); }, 150));
        document.getElementById('userRoleFilter').addEventListener('change', function () { state.role = this.value; render(); });
        SW.qsa('#userStatusFilter button').forEach(function (b) {
            b.addEventListener('click', function () {
                state.status = b.getAttribute('data-status');
                SW.qsa('#userStatusFilter button').forEach(function (x) { x.classList.toggle('active', x === b); });
                render();
            });
        });
        page.addEventListener('click', function (ev) {
            const el = ev.target.closest('[data-user-action]');
            if (el) { ev.preventDefault(); rowAction(el.getAttribute('data-user-action'), parseInt(el.getAttribute('data-id'), 10)); }
        });

        document.getElementById('usersBody').innerHTML = SW.skeletonRows(6, 4);
        load().catch(function (e) {
            document.getElementById('usersBody').innerHTML = '<tr><td colspan="6">' + SW.emptyState('bi-wifi-off', 'Unable to load users', e.message) + '</td></tr>';
        });
    });
})();
