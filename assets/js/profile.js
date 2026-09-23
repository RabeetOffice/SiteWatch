/* SiteWatch — profile page */
(function () {
    'use strict';

    // Largest file accepted for cropping; the cropped result sent to the server is small.
    const MAX_SOURCE_BYTES = 15 * 1024 * 1024;
    // Side of the square sent to the server (it stores 256 × 256; 512 keeps resampling sharp).
    const OUTPUT_SIZE = 512;
    const MAX_ZOOM = 8; // times the zoom that just covers the square

    async function submit(form, action) {
        const btn = form.querySelector('button[type="submit"]');
        SW.showErrors(form, {});
        const body = { action: action };
        new FormData(form).forEach(function (v, k) { body[k] = v; });
        SW.setLoading(btn, true, 'Saving…');
        try {
            const res = await SW.api('api/profile/update.php', { method: 'POST', body: body });
            SW.toast(res.message, 'success');
            if (action === 'password') form.reset();
            if (res.data && res.data.reload) setTimeout(function () { window.location.reload(); }, 700);
        } catch (e) { SW.showErrors(form, e.errors || {}); SW.toast(e.message, 'danger'); }
        finally { SW.setLoading(btn, false); }
    }

    /**
     * Square crop: the picture moves and scales under a fixed square frame. State is the picture's scale
     * (screen px per image px) and the position of its top-left corner inside the frame.
     */
    function Cropper(stage, img, zoomInput, previews) {
        this.stage = stage;
        this.img = img;
        this.zoomInput = zoomInput;
        this.previews = previews;
        this.pointers = new Map();
        this.frame = 0;
        this.bind();
    }

    Cropper.prototype.load = function (src) {
        const self = this;
        return new Promise(function (resolve, reject) {
            self.img.onload = function () {
                self.w = self.img.naturalWidth;
                self.h = self.img.naturalHeight;
                resolve();
            };
            self.img.onerror = function () { reject(new Error('This file is not an image the browser can open.')); };
            self.img.src = src;
        });
    };

    /** Call once the stage is visible (its size is known). Starts at "cover": the square is filled. */
    Cropper.prototype.reset = function () {
        this.size = this.stage.clientWidth;
        this.cover = this.size / Math.min(this.w, this.h);
        this.contain = this.size / Math.max(this.w, this.h);
        this.max = this.cover * MAX_ZOOM;
        this.setScale(this.cover, this.size / 2, this.size / 2, true);
    };

    Cropper.prototype.fit = function () { this.setScale(this.contain, this.size / 2, this.size / 2, true); };

    /** Zoom to scale s keeping the image point under (cx, cy) in place. */
    Cropper.prototype.setScale = function (s, cx, cy, center) {
        s = Math.min(this.max, Math.max(this.contain, s));
        if (center || this.scale === undefined) {
            this.x = (this.size - this.w * s) / 2;
            this.y = (this.size - this.h * s) / 2;
        } else {
            this.x = cx - (cx - this.x) * (s / this.scale);
            this.y = cy - (cy - this.y) * (s / this.scale);
        }
        this.scale = s;
        this.clamp();
        this.syncZoomInput();
        this.render();
    };

    /** Keep the square covered where the picture is large enough; centre it on an axis where it is not. */
    Cropper.prototype.clamp = function () {
        const dw = this.w * this.scale, dh = this.h * this.scale;
        this.x = dw >= this.size ? Math.min(0, Math.max(this.size - dw, this.x)) : (this.size - dw) / 2;
        this.y = dh >= this.size ? Math.min(0, Math.max(this.size - dh, this.y)) : (this.size - dh) / 2;
    };

    // The slider is logarithmic between "fit" and maximum zoom, so each step feels the same.
    Cropper.prototype.syncZoomInput = function () {
        const t = Math.log(this.scale / this.contain) / Math.log(this.max / this.contain);
        this.zoomInput.value = String(Math.round(t * 100));
    };
    Cropper.prototype.scaleFromInput = function () {
        const t = Number(this.zoomInput.value) / 100;
        return this.contain * Math.pow(this.max / this.contain, t);
    };

    Cropper.prototype.move = function (dx, dy) {
        this.x += dx;
        this.y += dy;
        this.clamp();
        this.render();
    };

    Cropper.prototype.render = function () {
        const self = this;
        this.img.style.transform = 'translate(' + this.x + 'px,' + this.y + 'px) scale(' + this.scale + ')';
        if (this.frame) return;
        this.frame = requestAnimationFrame(function () {
            self.frame = 0;
            self.previews.forEach(function (canvas) { self.draw(canvas); });
        });
    };

    /** Draw the square currently under the frame onto a canvas of any size. */
    Cropper.prototype.draw = function (canvas) {
        const ctx = canvas.getContext('2d');
        const k = canvas.width / this.size;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(this.img, this.x * k, this.y * k, this.w * this.scale * k, this.h * this.scale * k);
    };

    Cropper.prototype.toBlob = function () {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = OUTPUT_SIZE;
        this.draw(canvas);
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) { blob ? resolve(blob) : reject(new Error('The picture could not be prepared.')); }, 'image/png');
        });
    };

    Cropper.prototype.point = function (ev) {
        const r = this.stage.getBoundingClientRect();
        return { x: ev.clientX - r.left, y: ev.clientY - r.top };
    };

    Cropper.prototype.bind = function () {
        const self = this, stage = this.stage;

        stage.addEventListener('pointerdown', function (ev) {
            stage.setPointerCapture(ev.pointerId);
            self.pointers.set(ev.pointerId, self.point(ev));
            stage.focus({ preventScroll: true });
        });
        stage.addEventListener('pointermove', function (ev) {
            if (!self.pointers.has(ev.pointerId)) return;
            const prev = self.pointers.get(ev.pointerId);
            const now = self.point(ev);
            if (self.pointers.size === 1) {
                self.move(now.x - prev.x, now.y - prev.y);
            } else if (self.pointers.size === 2) {
                // Pinch: scale by the change in distance between the two fingers, around their midpoint.
                const other = Array.from(self.pointers.entries()).find(function (e) { return e[0] !== ev.pointerId; })[1];
                const before = Math.hypot(prev.x - other.x, prev.y - other.y);
                const after = Math.hypot(now.x - other.x, now.y - other.y);
                if (before > 0) self.setScale(self.scale * (after / before), (now.x + other.x) / 2, (now.y + other.y) / 2);
            }
            self.pointers.set(ev.pointerId, now);
        });
        ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (type) {
            stage.addEventListener(type, function (ev) { self.pointers.delete(ev.pointerId); });
        });
        stage.addEventListener('wheel', function (ev) {
            ev.preventDefault();
            const p = self.point(ev);
            self.setScale(self.scale * Math.exp(-ev.deltaY * 0.0015), p.x, p.y);
        }, { passive: false });
        stage.addEventListener('keydown', function (ev) {
            const step = ev.shiftKey ? 40 : 10;
            const c = self.size / 2;
            const keys = {
                ArrowLeft: function () { self.move(step, 0); }, ArrowRight: function () { self.move(-step, 0); },
                ArrowUp: function () { self.move(0, step); }, ArrowDown: function () { self.move(0, -step); },
                '+': function () { self.setScale(self.scale * 1.1, c, c); }, '=': function () { self.setScale(self.scale * 1.1, c, c); },
                '-': function () { self.setScale(self.scale / 1.1, c, c); },
            };
            if (keys[ev.key]) { ev.preventDefault(); keys[ev.key](); }
        });
        this.zoomInput.addEventListener('input', function () {
            self.setScale(self.scaleFromInput(), self.size / 2, self.size / 2);
        });
    };

    function readFile(file) {
        return new Promise(function (resolve, reject) {
            const reader = new FileReader();
            reader.onload = function () { resolve(reader.result); };
            reader.onerror = function () { reject(new Error('The file could not be read.')); };
            reader.readAsDataURL(file);
        });
    }

    function initAvatar() {
        const input = document.getElementById('avatarFile');
        const modalEl = document.getElementById('cropModal');
        if (!input || !modalEl) return;
        const drop = document.getElementById('avatarDrop');
        const removeBtn = document.getElementById('avatarRemove');
        const saveBtn = document.getElementById('cropSave');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const cropper = new Cropper(
            document.getElementById('cropStage'),
            document.getElementById('cropImage'),
            document.getElementById('cropZoom'),
            SW.qsa('[data-crop-preview]', modalEl)
        );
        let ready = false;

        async function open(file) {
            if (!file) return;
            if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) { SW.toast('Use a JPEG, PNG, WebP or GIF image.', 'warning'); return; }
            if (file.size > MAX_SOURCE_BYTES) { SW.toast('The picture is larger than 15 MB.', 'warning'); return; }
            try {
                await cropper.load(await readFile(file));
            } catch (e) { SW.toast(e.message, 'danger'); return; }
            ready = false;
            modal.show();
        }

        modalEl.addEventListener('shown.bs.modal', function () { cropper.reset(); ready = true; document.getElementById('cropStage').focus(); });
        modalEl.addEventListener('hidden.bs.modal', function () { input.value = ''; });
        input.addEventListener('change', function () { open(input.files && input.files[0]); });
        SW.qsa('[data-crop-zoom]', modalEl).forEach(function (b) {
            b.addEventListener('click', function () {
                const c = cropper.size / 2;
                cropper.setScale(cropper.scale * (b.getAttribute('data-crop-zoom') === '1' ? 1.2 : 1 / 1.2), c, c);
            });
        });
        document.getElementById('cropFit').addEventListener('click', function () { cropper.fit(); });
        window.addEventListener('resize', function () { if (ready && modalEl.classList.contains('show')) cropper.reset(); });

        if (drop) {
            ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (ev) { ev.preventDefault(); drop.classList.add('dragover'); }); });
            ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('dragover'); }); });
            drop.addEventListener('drop', function (ev) { ev.preventDefault(); open(ev.dataTransfer.files && ev.dataTransfer.files[0]); });
        }

        saveBtn.addEventListener('click', async function () {
            if (!ready) return;
            SW.setLoading(saveBtn, true, 'Saving…');
            try {
                const form = new FormData();
                form.append('action', 'upload');
                form.append('avatar', await cropper.toBlob(), 'avatar.png');
                const res = await SW.api('api/profile/avatar.php', { method: 'POST', body: form });
                modal.hide();
                SW.toast(res.message, 'success');
                setTimeout(function () { window.location.reload(); }, 600);
            } catch (e) {
                SW.toast(e.message, 'danger');
            } finally {
                SW.setLoading(saveBtn, false);
            }
        });

        if (removeBtn) {
            removeBtn.addEventListener('click', async function () {
                const ok = await SW.confirm({ title: 'Remove your profile picture?', message: 'Your initials will be shown instead.', confirmText: 'Remove picture' });
                if (!ok) return;
                try {
                    const res = await SW.api('api/profile/avatar.php', { method: 'POST', body: { action: 'remove' } });
                    SW.toast(res.message, 'success');
                    setTimeout(function () { window.location.reload(); }, 600);
                } catch (e) { SW.toast(e.message, 'danger'); }
            });
        }
    }

    document.addEventListener('sw:ready', function () {
        const profile = document.getElementById('profileForm');
        const password = document.getElementById('passwordForm');
        if (profile) profile.addEventListener('submit', function (ev) { ev.preventDefault(); submit(profile, 'profile'); });
        if (password) password.addEventListener('submit', function (ev) { ev.preventDefault(); submit(password, 'password'); });
        initAvatar();
    });
})();
