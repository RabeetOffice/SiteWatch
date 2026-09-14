<?php

declare(strict_types=1);

/**
 * Shared add/edit website form. Expects:
 *   $website   array|null  Existing row when editing
 *   $formMode  'create'|'edit'
 */

use App\Monitoring\StatusClassifier;
use App\Repositories\WebsiteRepository;
use App\Services\WebsiteService;

$website = $website ?? null;
$formMode = $formMode ?? 'create';
$isEdit = $formMode === 'edit' && $website !== null;

$defaultInterval = (int) setting('default_check_interval', 5);
$globalFailure = (int) setting('failure_threshold', 3);
$globalRecovery = (int) setting('recovery_threshold', 2);
$checks = $isEdit ? StatusClassifier::enabledChecks($website) : array_fill_keys(WebsiteService::CHECK_KEYS, true);
$alertsRaw = $isEdit && !empty($website['alerts_json']) ? json_decode((string) $website['alerts_json'], true) : null;
$alerts = [];
foreach (WebsiteService::ALERT_KEYS as $key) {
    $alerts[$key] = is_array($alertsRaw) && array_key_exists($key, $alertsRaw) ? (bool) $alertsRaw[$key] : true;
}
$checkDescriptions = [
    'http'          => 'Flag 4xx/5xx responses from the homepage.',
    'wp_errors'     => 'Detect "There has been a critical error on this website".',
    'response_time' => 'Warn when responses are slow, alert when critically slow.',
    'ssl'           => 'Inspect certificate validity and expiry (HTTPS only).',
    'redirects'     => 'Detect loops, excessive redirects and HTTPS→HTTP downgrades.',
    'maintenance'   => 'Detect a site stuck in WordPress maintenance mode.',
    'database'      => 'Detect "Error establishing a database connection".',
    'fatal_errors'  => 'Detect PHP fatal / parse errors printed on the page.',
];
$alertDescriptions = [
    'down'     => 'Outages, timeouts, DNS and HTTP 5xx errors.',
    'critical' => 'WordPress critical errors and database errors.',
    'slow'     => 'Critically slow responses (performance incidents).',
    'ssl'      => 'Certificate expiring in 30 / 14 / 7 days or expired.',
    'recovery' => 'When the website comes back online.',
];
$v = static fn (string $key, mixed $default = ''): string => (string) ($website[$key] ?? $default);
?>
<form id="websiteForm" class="needs-validation" novalidate data-mode="<?= e($formMode) ?>" data-id="<?= $isEdit ? (int) $website['id'] : 0 ?>">
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Website</h3><p class="sub">Basic details of the client website</p></div></div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="f-name">Website Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="f-name" name="name" maxlength="150" value="<?= e($v('name')) ?>" placeholder="Northern Star Press" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="f-client">Client Name</label>
                            <input type="text" class="form-control" id="f-client" name="client_name" maxlength="150" value="<?= e($v('client_name')) ?>" placeholder="Northern Star Press Ltd" list="clientList">
                            <datalist id="clientList"><?php foreach ((new WebsiteRepository(\App\Core\App::db()))->clients() as $client): ?><option value="<?= e($client) ?>"></option><?php endforeach; ?></datalist>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="f-url">Website URL <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                                <input type="text" class="form-control" id="f-url" name="url" maxlength="2048" value="<?= e($v('url')) ?>" placeholder="example.com" required inputmode="url" autocomplete="off">
                            </div>
                            <div class="form-text" id="urlPreview">Enter the public address. <span class="code-inline">example.com</span> becomes <span class="code-inline">https://example.com/</span>.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="f-type">Website Type</label>
                            <select class="form-select" id="f-type" name="type">
                                <?php foreach (WebsiteService::TYPE_LABELS as $key => $label): ?>
                                    <option value="<?= e($key) ?>"<?= $v('type', 'wordpress') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="f-interval">Monitoring Interval</label>
                            <select class="form-select" id="f-interval" name="check_interval">
                                <?php foreach (WebsiteRepository::INTERVALS as $interval): ?>
                                    <option value="<?= $interval ?>"<?= (int) $v('check_interval', (string) $defaultInterval) === $interval ? ' selected' : '' ?>><?= $interval ?> minute<?= $interval === 1 ? '' : 's' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="f-enabled" name="monitoring_enabled" value="1"<?= (!$isEdit || (int) $website['monitoring_enabled'] === 1) ? ' checked' : '' ?>>
                                <label class="form-check-label fw-600" for="f-enabled">Monitoring enabled</label>
                                <div class="form-text">Turn off to pause monitoring without deleting the website.</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="f-notes">Notes</label>
                            <textarea class="form-control" id="f-notes" name="notes" rows="2" maxlength="2000" placeholder="Hosting provider, contacts, anything useful for the team…"><?= e($v('notes')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Checks</h3><p class="sub">What SiteWatch looks for on every check</p></div></div>
                <div class="sw-card-body">
                    <div class="option-grid">
                        <?php foreach (WebsiteService::CHECK_LABELS as $key => $label): ?>
                            <label class="option-card">
                                <input type="checkbox" class="form-check-input" name="checks[<?= e($key) ?>]" value="1"<?= $checks[$key] ? ' checked' : '' ?>>
                                <span><span class="t d-block"><?= e($label) ?></span><span class="d"><?= e($checkDescriptions[$key]) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Alerts</h3><p class="sub">Per-website overrides of the global rules</p></div></div>
                <div class="sw-card-body">
                    <div class="d-flex flex-column gap-2">
                        <?php foreach (WebsiteService::ALERT_LABELS as $key => $label): ?>
                            <label class="option-card">
                                <input type="checkbox" class="form-check-input" name="alerts[<?= e($key) ?>]" value="1"<?= $alerts[$key] ? ' checked' : '' ?>>
                                <span><span class="t d-block"><?= e($label) ?></span><span class="d"><?= e($alertDescriptions[$key]) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text mt-3">Alerts are only sent when the matching global rule is also enabled under <a href="<?= e(base_url('admin/notifications.php')) ?>">Notifications</a>.</div>
                </div>
            </div>

            <div class="sw-card mb-4">
                <div class="sw-card-header"><div><h3>Confirmation</h3><p class="sub">False-positive protection</p></div></div>
                <div class="sw-card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="f-failure">Failures to confirm</label>
                            <input type="number" class="form-control" id="f-failure" name="failure_threshold" min="1" max="10" value="<?= e($v('failure_threshold')) ?>" placeholder="<?= $globalFailure ?>">
                            <div class="form-text">Global: <?= $globalFailure ?></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="f-recovery">Successes to recover</label>
                            <input type="number" class="form-control" id="f-recovery" name="recovery_threshold" min="1" max="10" value="<?= e($v('recovery_threshold')) ?>" placeholder="<?= $globalRecovery ?>">
                            <div class="form-text">Global: <?= $globalRecovery ?></div>
                        </div>
                    </div>
                    <div class="form-text mt-2">Leave empty to use the global thresholds from Monitoring Settings.</div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg" id="websiteSubmit"><i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save Changes' : 'Add Website' ?></button>
                <?php if (!$isEdit): ?>
                    <div class="form-check ms-1">
                        <input class="form-check-input" type="checkbox" id="f-check-now" name="check_now" value="1" checked>
                        <label class="form-check-label" for="f-check-now">Run the first check immediately</label>
                    </div>
                <?php endif; ?>
                <a href="<?= e($isEdit ? base_url('admin/website-details.php?id=' . (int) $website['id']) : base_url('admin/websites.php')) ?>" class="btn btn-light">Cancel</a>
            </div>
        </div>
    </div>
</form>
