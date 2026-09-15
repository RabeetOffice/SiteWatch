<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;

App::auth()->requireLogin();
$user = App::auth()->user();

$pageTitle = 'Profile';
$activeNav = 'profile';
$pageScripts = ['profile.js'];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-form-col">
    <div class="profile-identity mb-4">
        <span class="sw-avatar lg" aria-hidden="true"><?= e($initials) ?></span>
        <div class="min-w-0">
            <div class="fw-600" style="font-size:16px"><?= e($user['name']) ?></div>
            <div class="text-muted fs-13"><?= e($user['email']) ?> · Administrator</div>
        </div>
    </div>

    <form id="profileForm" novalidate>
        <section class="sw-card mb-4">
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
        <section class="sw-card mb-4">
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

    <details class="sw-disclosure">
        <summary>Session details <span class="hint">last sign-in</span></summary>
        <div class="sw-disclosure-body">
            <dl class="kv-list mb-0">
                <dt>Role</dt><dd>Administrator</dd>
                <dt>Last sign-in</dt><dd><?= e($user['last_login_at'] ? format_datetime($user['last_login_at']) . ' (' . time_ago($user['last_login_at']) . ')' : 'Unknown') ?></dd>
                <?php if (!empty($user['last_login_ip'])): ?><dt>From IP address</dt><dd class="mono"><?= e($user['last_login_ip']) ?></dd><?php endif; ?>
            </dl>
        </div>
    </details>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
