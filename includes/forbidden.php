<?php

declare(strict_types=1);

/**
 * "Access denied" page, rendered by require_permission(). Expects $deniedPermission.
 */

use App\Core\App;
use App\Core\Permission;

$pageTitle = 'Access denied';
$activeNav = '';
$pageScripts = [];
$hidePageHead = true;

require __DIR__ . '/header.php';

$roleName = (string) (App::auth()->user()['role_name'] ?? 'your');
?>

<h1 class="visually-hidden">Access denied</h1>
<section class="sw-card">
    <div class="empty-state py-5">
        <div class="ico"><i class="bi bi-shield-lock" aria-hidden="true"></i></div>
        <h4>You don't have access to this page</h4>
        <p>The <b><?= e($roleName) ?></b> role does not include “<?= e($deniedPermission === Permission::ALL ? 'full Administrator access' : Permission::label($deniedPermission)) ?>”. Ask an administrator if you need it.</p>
        <a class="btn btn-sm btn-primary" href="<?= e(base_url('admin/dashboard.php')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to the dashboard</a>
    </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
