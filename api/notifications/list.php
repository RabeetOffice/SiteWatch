<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$pagination = Api::pagination(10, 100);
$websiteId = Request::int('website_id');
$result = ServiceFactory::notifications()->recent($pagination['per_page'], $pagination['offset'], $websiteId > 0 ? $websiteId : null);

$eventLabels = ['down' => 'Website Down', 'recovery' => 'Website Recovered', 'ssl_expiry' => 'SSL Expiry', 'test' => 'Test Notification'];
$channelLabels = ['email' => 'Email', 'telegram' => 'Telegram', 'none' => 'No channel'];

Response::success('', [
    'rows' => array_map(static fn (array $n): array => [
        'id'            => (int) $n['id'],
        'website_id'    => $n['website_id'] !== null ? (int) $n['website_id'] : null,
        'website_name'  => $n['website_name'] ?? null,
        'domain'        => $n['domain'] ?? null,
        'incident_id'   => $n['incident_id'] !== null ? (int) $n['incident_id'] : null,
        'channel'       => $n['channel'],
        'channel_label' => $channelLabels[$n['channel']] ?? ucfirst((string) $n['channel']),
        'event'         => $n['event'],
        'event_label'   => $eventLabels[$n['event']] ?? ucwords(str_replace('_', ' ', (string) $n['event'])),
        'recipient'     => $n['recipient'],
        'subject'       => $n['subject'],
        'status'        => $n['status'],
        'error_message' => $n['error_message'],
        'created_at'    => $n['created_at'],
        'created_label' => format_datetime($n['created_at']),
    ], $result['rows']),
    'total'    => $result['total'],
    'page'     => $pagination['page'],
    'per_page' => $pagination['per_page'],
]);
