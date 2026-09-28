<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Services\AvatarService;

App::auth()->requireLogin();
$user = App::auth()->user();

$pageTitle = 'Profile';
$activeNav = 'profile';
$pageScripts = ['profile.js', 'profile-device.js'];
$pageSubtitle = 'Your account, and how SiteWatch behaves on this device.';
$hasAvatar = AvatarService::url($user) !== null;

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="profile-identity mb-3">
        <?= user_avatar($user, 'lg') ?>
        <div class="min-w-0">
            <div class="fw-600" style="font-size:16px"><?= e($user['name']) ?></div>
            <div class="text-muted fs-13"><?= e($user['email']) ?> · <?= e($user['role_name'] ?? '') ?></div>
        </div>
</div>

<div class="sw-form-grid">
<div class="d-flex flex-column gap-3 min-w-0">

    <section class="sw-card" aria-labelledby="avatarTitle">
        <div class="sw-card-header"><div><h3 id="avatarTitle">Profile picture</h3><p class="sub">Shown in the top bar, the sidebar and the Users list</p></div></div>
        <div class="sw-card-body">
            <div class="avatar-editor">
                <div class="avatar-drop" id="avatarDrop" data-initials="<?= e(\App\Services\TeamService::initials((string) $user['name'])) ?>"><?= user_avatar($user, 'xl') ?></div>
                <div class="actions">
                    <div class="btns">
                        <label class="btn btn-primary mb-0" for="avatarFile"><i class="bi bi-upload" aria-hidden="true"></i> <?= $hasAvatar ? 'Change picture' : 'Upload picture' ?></label>
                        <input type="file" id="avatarFile" accept="image/jpeg,image/png,image/webp,image/gif" class="visually-hidden">
                        <button type="button" class="btn btn-light" id="avatarRemove"<?= $hasAvatar ? '' : ' hidden' ?>><i class="bi bi-trash" aria-hidden="true"></i> Remove</button>
                    </div>
                    <div class="form-text m-0">JPEG, PNG, WebP or GIF, up to 15 MB. You can crop it to a square before saving. You can also drop an image on the circle.</div>
                </div>
            </div>
        </div>
    </section>

    <form id="profileForm" novalidate>
        <section class="sw-card">
            <div class="sw-card-header"><div><h3>Account details</h3><p class="sub">Your name and sign-in email address</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="p-name">Full name</label>
                        <input type="text" class="form-control" id="p-name" name="name" maxlength="100" value="<?= e($user['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="p-email">Email address</label>
                        <input type="email" class="form-control" id="p-email" name="email" maxlength="190" value="<?= e($user['email']) ?>" required autocomplete="username">
                        <div class="form-text">Used to sign in.</div>
                    </div>
                </div>
            </div>
            <div class="sw-card-footer justify-content-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save profile</button>
            </div>
        </section>
    </form>

    <form id="passwordForm" novalidate>
        <section class="sw-card">
            <div class="sw-card-header"><div><h3>Password</h3><p class="sub">Use at least 10 characters</p></div></div>
            <div class="sw-card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="p-current">Current password</label>
                        <input type="password" class="form-control" id="p-current" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="p-new">New password</label>
                        <input type="password" class="form-control" id="p-new" name="new_password" minlength="10" required autocomplete="new-password" aria-describedby="pwHelp">
                        <div class="form-text" id="pwHelp">At least 10 characters.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="p-confirm">Confirm new password</label>
                        <input type="password" class="form-control" id="p-confirm" name="new_password_confirmation" minlength="10" required autocomplete="new-password">
                    </div>
                </div>
                <p class="text-muted fs-13 mb-0 mt-3">Changing your password signs out every other device where you chose to stay signed in.</p>
            </div>
            <div class="sw-card-footer justify-content-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-key" aria-hidden="true"></i> Update password</button>
            </div>
        </section>
    </form>

</div>

<div class="d-flex flex-column gap-3 min-w-0">
    <section class="sw-card" id="device" aria-labelledby="deviceTitle" style="scroll-margin-top:80px">
        <div class="sw-card-header"><div><h3 id="deviceTitle">This device</h3><p class="sub">Settings that belong to this browser, not to your account</p></div></div>
        <div class="sw-card-body">
            <div class="device-row">
                <div class="activity-icon tone-primary" aria-hidden="true"><i class="bi bi-window-plus"></i></div>
                <div class="min-w-0 flex-grow-1">
                    <div class="fw-600">SiteWatch app</div>
                    <div class="fs-13 text-muted" data-installed-note hidden>You are using the installed app.</div>
                    <div class="fs-13 text-muted" data-install-hint>Install SiteWatch to open it from the Start menu, Dock or desktop in its own window, with open incidents on its icon.</div>
                </div>
                <button type="button" class="btn btn-light" data-install-app hidden><i class="bi bi-download" aria-hidden="true"></i>Install</button>
            </div>
            <div class="device-row">
                <div class="activity-icon tone-primary" aria-hidden="true"><i class="bi bi-bell"></i></div>
                <div class="min-w-0 flex-grow-1">
                    <div class="fw-600">Desktop notifications</div>
                    <div class="fs-13 text-muted" data-push-status>Checking this browser…</div>
                </div>
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="pushToggle" aria-describedby="pushHelp" disabled>
                    <label class="visually-hidden" for="pushToggle">Desktop notifications on this device</label>
                </div>
            </div>
            <div data-push-options hidden>
                <div class="breakdown-title mt-3">Notify me about</div>
                <div class="sw-check-grid" data-push-events></div>
                <div class="d-flex gap-2 mt-3 flex-wrap">
                    <button type="button" class="btn btn-sm btn-light" data-push-test><i class="bi bi-send" aria-hidden="true"></i>Send a test</button>
                </div>
            </div>
            <p class="form-text mb-0 mt-3" id="pushHelp">Notifications arrive even when SiteWatch is closed. They use the same alert rules as email and the other channels.</p>
        </div>
    </section>

    <section class="sw-card" aria-labelledby="devicesTitle" data-push-devices-card hidden>
        <div class="sw-card-header"><div><h3 id="devicesTitle">Devices with notifications</h3><p class="sub">Browsers and apps where you turned them on</p></div></div>
        <ul class="device-list" data-push-devices></ul>
    </section>

    <details class="sw-disclosure">
        <summary>Session details <span class="hint">last sign-in</span></summary>
        <div class="sw-disclosure-body">
            <dl class="kv-list mb-0">
                <dt>Role</dt><dd><?= e($user['role_name'] ?? '') ?><?= can('roles.manage') ? ' · <a href="' . e(base_url('admin/team.php?tab=roles')) . '">roles &amp; permissions</a>' : '' ?></dd>
                <dt>Last sign-in</dt><dd><?= e($user['last_login_at'] ? format_datetime($user['last_login_at']) . ' (' . time_ago($user['last_login_at']) . ')' : 'Unknown') ?></dd>
                <?php if (!empty($user['last_login_ip'])): ?><dt>From IP address</dt><dd class="mono"><?= e($user['last_login_ip']) ?></dd><?php endif; ?>
            </dl>
        </div>
    </details>
</div>
</div>

<div class="modal fade" id="cropModal" tabindex="-1" aria-labelledby="cropTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" style="font-size:16px" id="cropTitle">Crop your picture</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button>
            </div>
            <div class="modal-body">
                <div class="crop-stage" id="cropStage" tabindex="0" role="application"
                     aria-label="Crop area. Drag or use the arrow keys to move the picture, plus and minus to zoom.">
                    <img id="cropImage" alt="">
                    <div class="crop-frame" aria-hidden="true"></div>
                </div>
                <div class="crop-controls">
                    <button type="button" class="btn-icon btn-sm" data-crop-zoom="-1" aria-label="Zoom out"><i class="bi bi-zoom-out" aria-hidden="true"></i></button>
                    <input type="range" class="form-range" id="cropZoom" min="0" max="100" step="1" value="0" aria-label="Zoom">
                    <button type="button" class="btn-icon btn-sm" data-crop-zoom="1" aria-label="Zoom in"><i class="bi bi-zoom-in" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-sm btn-light" id="cropFit" title="Fit the whole picture width or height">Fit</button>
                </div>
                <div class="crop-previews" aria-label="Preview">
                    <canvas width="96" height="96" style="width:48px;height:48px" data-crop-preview></canvas>
                    <canvas width="56" height="56" style="width:28px;height:28px" data-crop-preview></canvas>
                    <canvas class="sq" width="96" height="96" style="width:48px;height:48px" data-crop-preview></canvas>
                    <span class="fs-12 text-muted">Preview</span>
                </div>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="cropSave"><i class="bi bi-check-lg" aria-hidden="true"></i> Save picture</button>
            </div>
        </div>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
