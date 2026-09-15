<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\DomainRepository;
use App\Services\DomainService;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'domains.view');

$pagination = Api::pagination(25, 100);
$filter = Request::string('filter', 'all');
$sort = Request::string('sort', 'expiry');
$criteria = [
    'q'        => mb_substr(Request::string('q'), 0, 100),
    'filter'   => in_array($filter, DomainRepository::FILTERS, true) ? $filter : 'all',
    'sort'     => in_array($sort, DomainRepository::SORTS, true) ? $sort : 'expiry',
    'provider' => mb_substr(Request::string('provider'), 0, 120),
    'country'  => mb_substr(Request::string('country'), 0, 2),
];

$repo = ServiceFactory::domains();
$maxAgeHours = max(1, App::settings()->getInt('domain_check_interval_hours', 24));
$result = $repo->search($criteria, DomainService::EXPIRY_WARNING_DAYS, $pagination['per_page'], $pagination['offset']);

$summary = $repo->summary(DomainService::EXPIRY_WARNING_DAYS);
$summary['avg_age_label'] = $summary['avg_age_days'] !== null
    ? DomainService::age(utc_now()->modify('-' . $summary['avg_age_days'] . ' days')->format('Y-m-d H:i:s'))['label']
    : '—';
foreach ($summary['countries'] as &$country) {
    $country['name'] = DomainService::countryName($country['code']) ?? $country['code'];
}
unset($country);

$data = [
    'rows'     => array_map(static fn (array $row): array => DomainService::presentRow($row, $maxAgeHours), $result['rows']),
    'total'    => $result['total'],
    'page'     => $pagination['page'],
    'per_page' => $pagination['per_page'],
    'summary'  => $summary,
];
if (Request::bool('with_stale')) {
    $data['stale_ids'] = array_map(static fn (array $w): int => (int) $w['id'], $repo->stale($maxAgeHours, 1000));
}

Response::success('', $data);
