<?php

declare(strict_types=1);

// This page must work while a database update is waiting.
define('SW_ALLOW_PENDING_SCHEMA', true);
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\App;
use App\Core\Migrator;
use App\Core\Permission;
use App\Core\Release;

App::auth()->requireLogin();

$migrator = Migrator::create();
$current = $migrator->currentVersion();
$pending = $migrator->pending();
$history = $migrator->history();
$isAdmin = can(Permission::ALL);
if (!$isAdmin && $pending === []) {
    // Nothing to wait for: the page itself is for administrators.
    require_permission(Permission::ALL);
}

$appVersion = Release::VERSION;
$dbRelease = Migrator::releaseFor($current);
$requiredRelease = Migrator::releaseFor(Migrator::VERSION);
$inSync = $pending === [];
$autoMigrate = (bool) config('app.auto_migrate', false);
try {
    $dbServer = (string) App::db()->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable) {
    $dbServer = 'unknown';
}

$pageTitle = 'Updates';
$pageSubtitle = 'Keep this server’s database in step with the code deployed to it.';
$activeNav = 'updates';
$pageScripts = ['updates.js'];

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-form-col">
    <div class="version-grid mb-4" role="group" aria-label="Versions">
        <div class="version-tile">
            <span class="label">Application</span>
            <span class="value">v<?= e($appVersion) ?></span>
            <span class="hint"><?= e(Release::NOTES[$appVersion]['title'] ?? '') ?></span>
        </div>
        <div class="version-tile">
            <span class="label">Database</span>
            <span class="value">v<?= e($dbRelease) ?></span>
            <span class="hint">Schema <?= (int) $current ?><?= $inSync ? '' : ' · needs schema ' . (int) Migrator::VERSION . ' (v' . e($requiredRelease) . ')' ?></span>
        </div>
        <div class="version-tile">
            <span class="label">Status</span>
            <span class="value"><?= $inSync ? '<span class="sw-pill tone-success"><i class="bi bi-check2-circle" aria-hidden="true"></i> In sync</span>' : '<span class="sw-pill tone-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Update needed</span>' ?></span>
            <span class="hint">Released <?= e(format_date(Release::NOTES[$appVersion]['date'] ?? null)) ?></span>
        </div>
    </div>

    <section class="sw-card mb-4" aria-labelledby="updateStatusTitle">
        <div class="sw-card-body">
            <div class="update-status">
                <?php if ($pending === []): ?>
                    <span class="update-icon tone-success" aria-hidden="true"><i class="bi bi-database-check"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <h2 id="updateStatusTitle">Your database is up to date</h2>
                        <p>The database is at <b>v<?= e($dbRelease) ?></b> (schema <?= (int) $current ?>), which is what SiteWatch <?= e($appVersion) ?> needs. After the next deploy, SiteWatch brings you here if the new code needs database changes.</p>
                    </div>
                <?php else: ?>
                    <span class="update-icon tone-warning" aria-hidden="true"><i class="bi bi-database-exclamation"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <h2 id="updateStatusTitle">Database update required</h2>
                        <p>
                            The code on this server (SiteWatch <?= e($appVersion) ?>) needs database <b>v<?= e($requiredRelease) ?></b> (schema <?= (int) Migrator::VERSION ?>); this database is at <b>v<?= e($dbRelease) ?></b> (schema <?= (int) $current ?>).
                            Other pages are unavailable until the update runs. Website monitoring keeps running meanwhile.
                        </p>
                        <?php if ($isAdmin): ?>
                            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                                <button type="button" class="btn btn-primary" id="btnRunUpdate"><i class="bi bi-database-up" aria-hidden="true"></i> Update database</button>
                                <span class="fs-13 text-muted">Usually takes a few seconds.</span>
                            </div>
                            <div class="alert alert-danger mt-3 mb-0" id="updateError" hidden role="alert">
                                <b>The update did not finish.</b> <span data-error></span>
                            </div>
                        <?php else: ?>
                            <p class="mb-0"><b>Ask an administrator to sign in and run the update.</b></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($pending !== []): ?>
    <section class="sw-card mb-4" aria-labelledby="changesTitle">
        <div class="sw-card-header"><div><h3 id="changesTitle">What this update changes</h3><p class="sub">Applied in order</p></div></div>
        <div class="sw-card-body">
            <?php foreach ($pending as $i => $step): ?>
                <div class="<?= $i > 0 ? 'divider-top' : '' ?>">
                    <div class="fw-600 mb-1">v<?= e($step['release'] ?? '') ?> · schema <?= (int) $step['version'] ?> · <?= e($step['title']) ?></div>
                    <ul class="change-list">
                        <?php foreach ($step['changes'] as $change): ?><li><?= e($change) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="coverage-note mb-4" role="note">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <div>
            <strong>Your data stays where it is</strong>
            Updates only add or change tables and columns in this server’s own database. Websites, check history, incidents,
            users and settings are kept, and nothing is copied from any other installation — such as your local copy.
            Each step checks what already exists, so an interrupted update can simply be run again.
            Before updating, take a database backup in your hosting control panel (on Hostinger: hPanel → Backups).
        </div>
    </div>

    <section class="sw-card mb-4" aria-labelledby="historyTitle">
        <div class="sw-card-header"><div><h3 id="historyTitle">Update history</h3><p class="sub">Database updates applied on this server</p></div></div>
        <?php if ($history === []): ?>
            <div class="sw-card-body text-muted fs-13">
                <?= $current > 0 ? 'No updates have been recorded yet. This database was set up at version ' . (int) $current . ' or updated before history was kept.' : 'No updates have been applied yet.' ?>
            </div>
        <?php else: ?>
            <div class="sw-table-wrap">
                <table class="sw-table compact">
                    <thead><tr><th scope="col">Version</th><th scope="col">Applied</th><th scope="col" class="num">Duration</th></tr></thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td>v<?= e((string) ($entry['release'] ?? Migrator::releaseFor((int) $entry['version']))) ?> <span class="text-muted fs-12">schema <?= (int) $entry['version'] ?></span><?= isset(Migrator::STEPS[(int) $entry['version']]) ? '<div class="fs-12 text-muted">' . e(Migrator::STEPS[(int) $entry['version']]['title']) . '</div>' : '' ?></td>
                                <td class="nowrap"><?= e(format_datetime((string) $entry['applied_at'])) ?><div class="fs-12 text-muted"><?= e(time_ago((string) $entry['applied_at'])) ?></div></td>
                                <td class="num"><?= e(format_ms((int) ($entry['duration_ms'] ?? 0))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="sw-card mb-4" aria-labelledby="releaseNotesTitle">
        <div class="sw-card-header"><div><h3 id="releaseNotesTitle">Release notes</h3><p class="sub">What changed in each version of SiteWatch</p></div></div>
        <div class="sw-card-body">
            <?php $first = true; foreach (Release::NOTES as $version => $notes): $anchor = 'rel-' . str_replace('.', '-', $version); ?>
                <article class="release<?= $first ? '' : ' divider-top' ?>" aria-labelledby="<?= e($anchor) ?>">
                    <div class="release-head">
                        <h4 id="<?= e($anchor) ?>">v<?= e($version) ?></h4>
                        <?php if ($version === $appVersion): ?><span class="sw-pill tone-success">Installed</span><?php endif; ?>
                        <?php if ($notes['schema'] !== null && $notes['schema'] > Migrator::BASELINE): ?><span class="sw-pill tone-info" title="This release changes the database">Database update</span><?php endif; ?>
                        <span class="fs-12 text-muted ms-auto"><?= e(format_date($notes['date'])) ?></span>
                    </div>
                    <div class="fw-600 fs-13 mb-2"><?= e($notes['title']) ?></div>
                    <?php foreach (['added' => 'Added', 'improved' => 'Improved', 'fixed' => 'Fixed'] as $key => $label): ?>
                        <?php if (!empty($notes[$key])): ?>
                            <div class="release-group"><span class="release-tag tag-<?= e($key) ?>"><?= e($label) ?></span>
                                <ul class="change-list"><?php foreach ($notes[$key] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </article>
            <?php $first = false; endforeach; ?>
        </div>
    </section>

    <details class="sw-disclosure">
        <summary>Details <span class="hint">versions and other ways to update</span></summary>
        <div class="sw-disclosure-body">
            <dl class="kv-list mb-3">
                <dt>SiteWatch</dt><dd><?= e($appVersion) ?></dd>
                <dt>Database version</dt><dd>v<?= e($dbRelease) ?> · schema <?= (int) $current ?> <span class="text-muted">· this code needs schema <?= (int) Migrator::VERSION ?></span></dd>
                <dt>Database server</dt><dd><?= e($dbServer) ?></dd>
                <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
                <dt>Automatic updates</dt><dd><?= $autoMigrate ? '<span class="sw-pill tone-warning">on (DB_AUTO_MIGRATE=true)</span>' : 'Off — updates wait for this page' ?></dd>
            </dl>
            <div class="form-label">Command line (SSH)</div>
            <pre class="copy-box mb-2">php <?= e(str_replace('\\', '/', (string) config('app.paths.root'))) ?>/database/migrate.php</pre>
            <p class="fs-13 text-muted mb-0">To apply updates automatically after every deploy instead, set <span class="code-inline">DB_AUTO_MIGRATE=true</span> in the server’s <span class="code-inline">.env</span> file.</p>
        </div>
    </details>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
