<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;

App::auth()->requireLogin();
$user = App::auth()->user();

$pageTitle = 'Profile';
$pageSubtitle = 'Your administrator account.';
$activeNav = 'profile';
$pageScripts = ['profile.js'];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="row g-4">
    <div class="col-xl-6">
        <form id="profileForm" novalidate>
            <div class="sw-card">
                <div class="sw-card-header"><div><h3>Account Details</h3><p class="sub">Name and sign-in email address</p></div></div>
                <div class="sw-card-body">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <span class="sw-avatar" style="width:52px;height:52px;font-size:18px"><?= e(strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
                        <div><div class="fw-600"><?= e($user['name']) ?></div><div class="text-muted fs-13">Administrator · last sign-in <?= e($user['last_login_at'] ? time_ago($user['last_login_at']) : 'unknown') ?><?= $user['last_login_ip'] ? ' from ' . e($user['last_login_ip']) : '' ?></div></div>
                    </div>
                    <div class="mb-3"><label class="form-label" for="p-name">Full Name</label><input type="text" class="form-control" id="p-name" name="name" maxlength="100" value="<?= e($user['name']) ?>" required></div>
                    <div class="mb-0"><label class="form-label" for="p-email">Email</label><input type="email" class="form-control" id="p-email" name="email" maxlength="190" value="<?= e($user['email']) ?>" required autocomplete="username"></div>
                </div>
                <div class="sw-card-footer d-flex justify-content-end"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Profile</button></div>
            </div>
        </form>
    </div>
    <div class="col-xl-6">
        <form id="passwordForm" novalidate>
            <div class="sw-card">
                <div class="sw-card-header"><div><h3>Change Password</h3><p class="sub">Use at least 10 characters. Existing "remember me" sessions are revoked.</p></div></div>
                <div class="sw-card-body">
                    <div class="mb-3"><label class="form-label" for="p-current">Current Password</label><input type="password" class="form-control" id="p-current" name="current_password" required autocomplete="current-password"></div>
                    <div class="mb-3"><label class="form-label" for="p-new">New Password</label><input type="password" class="form-control" id="p-new" name="new_password" minlength="10" required autocomplete="new-password"></div>
                    <div class="mb-0"><label class="form-label" for="p-confirm">Confirm New Password</label><input type="password" class="form-control" id="p-confirm" name="new_password_confirmation" minlength="10" required autocomplete="new-password"></div>
                </div>
                <div class="sw-card-footer d-flex justify-content-end"><button type="submit" class="btn btn-primary"><i class="bi bi-key"></i> Update Password</button></div>
            </div>
        </form>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
