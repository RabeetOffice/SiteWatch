<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;

$auth = App::auth();
if ($auth->check()) {
    Response::redirect(base_url('admin/dashboard.php'));
}

$appName = (string) setting('app_name', 'SiteWatch') ?: 'SiteWatch';
$csrf = App::csrf();
$error = null;
$email = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');

/**
 * Only allow redirects to paths inside this application.
 */
$safeNext = static function (string $next): ?string {
    if ($next === '' || str_contains($next, '://') || str_starts_with($next, '//') || str_contains($next, "\n")) {
        return null;
    }
    $basePath = (string) parse_url(base_url(), PHP_URL_PATH);
    $path = (string) parse_url($next, PHP_URL_PATH);
    if ($basePath !== '' && $basePath !== '/' && !str_starts_with($path, rtrim($basePath, '/') . '/')) {
        return null;
    }
    if (str_contains($path, 'login.php') || str_contains($path, 'logout.php')) {
        return null;
    }
    return $next;
};

if (Request::isPost()) {
    if (!$csrf->validateRequest()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);
        $result = $auth->attempt($email, $password, $remember, Request::ip());
        if ($result['success']) {
            ActivityService::log('auth.login', sprintf('%s signed in', $auth->user()['name'] ?? $email));
            $target = $safeNext($next) ?? base_url('admin/dashboard.php');
            Response::redirect($target);
        }
        $error = $result['message'];
    }
}
$nonce = defined('SW_CSP_NONCE') ? SW_CSP_NONCE : '';
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · <?= e($appName) ?></title>
    <link rel="icon" href="<?= e(base_url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/redesign.css')) ?>" rel="stylesheet">
    <script nonce="<?= e($nonce) ?>">
        (function () { try { var t = localStorage.getItem('sw-theme'); if (t !== 'dark' && t !== 'light') { t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; } document.documentElement.setAttribute('data-bs-theme', t); } catch (e) {} })();
    </script>
</head>
<body class="sw-body">
<div class="auth-page">
    <button type="button" class="btn-icon auth-theme-toggle" aria-label="Toggle theme"><i class="bi bi-moon-stars"></i></button>
    <div class="auth-card">
        <div class="brand"><span class="sw-brand-mark"><i class="bi bi-broadcast"></i></span><?= e($appName) ?></div>
        <h1>Welcome back</h1>
        <p class="lead-text">Sign in to your website monitoring dashboard.</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2" role="alert"><i class="bi bi-exclamation-circle"></i><div><?= e($error) ?></div></div>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('login.php')) ?>" novalidate>
            <?= $csrf->field() ?>
            <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" placeholder="you@agency.com" required autocomplete="username" autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="••••••••••" required autocomplete="current-password">
            </div>
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100">Sign In</button>
        </form>
        <div class="auth-footer"><?= e($appName) ?> · Website monitoring for agencies</div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
