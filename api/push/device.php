<?php

declare(strict_types=1);

/**
 * Desktop notifications for the signed-in user.
 *
 *   GET                         public key, the user's devices and the event choices
 *   POST action=subscribe       save this browser's push subscription (endpoint, keys, events)
 *   POST action=unsubscribe     forget this browser (endpoint) or one device (id)
 *   POST action=test            send a test notification to the user's devices
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\PushSubscriptionRepository;
use App\Services\PushService;

Api::boot(['GET', 'POST']);

$user = App::auth()->user();
$userId = (int) ($user['id'] ?? 0);
$repo = new PushSubscriptionRepository(App::db());
$push = PushService::create();

$present = static fn (array $s): array => [
    'id'           => (int) $s['id'],
    'device'       => (string) ($s['device'] ?: 'Unknown browser'),
    'endpoint_end' => substr(hash('sha256', (string) $s['endpoint']), 0, 12),
    'events'       => $s['events'] !== null ? (json_decode((string) $s['events'], true) ?: []) : array_keys(PushService::EVENTS),
    'created_at'   => $s['created_at'],
    'last_used_at' => $s['last_used_at'],
    'last_error'   => $s['last_error'],
];

if (Request::method() === 'GET') {
    try {
        $key = $push->publicKey();
        $error = null;
    } catch (Throwable $e) {
        $key = null;
        $error = $e->getMessage();
    }
    Response::success('', [
        'public_key' => $key,
        'error'      => $error,
        'events'     => PushService::EVENTS,
        'devices'    => array_map($present, $repo->forUser($userId)),
        'allowed'    => can('incidents.view'),
    ]);
}

$action = Request::string('action');
if ($action === 'subscribe') {
    $input = Request::all();
    $endpoint = (string) ($input['endpoint'] ?? '');
    $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
    if (!preg_match('#^https://[^\s]{10,990}$#', $endpoint) || empty($keys['p256dh']) || empty($keys['auth'])) {
        Response::error('The browser sent an incomplete subscription. Try again, or use another browser.', [], 422);
    }
    $events = array_values(array_intersect(array_map('strval', (array) ($input['events'] ?? array_keys(PushService::EVENTS))), array_keys(PushService::EVENTS)));
    $id = $repo->save($userId, $endpoint, (string) $keys['p256dh'], (string) $keys['auth'], (string) ($input['encoding'] ?? 'aes128gcm'),
        mb_substr(trim((string) ($input['device'] ?? '')), 0, 120), $events);
    Response::success('Desktop notifications are on for this device.', ['id' => $id, 'devices' => array_map($present, $repo->forUser($userId))]);
}

if ($action === 'unsubscribe') {
    $id = Request::int('id');
    $removed = $id > 0 ? $repo->deleteForUser($userId, $id) : $repo->deleteByEndpoint($userId, Request::string('endpoint'));
    Response::success($removed ? 'Desktop notifications are off for that device.' : 'That device was already removed.', ['devices' => array_map($present, $repo->forUser($userId))]);
}

if ($action === 'test') {
    try {
        $result = $push->sendTest($userId);
    } catch (Throwable $e) {
        App::logger('notifications')->warning('Test push failed', ['error' => $e->getMessage()]);
        Response::error('The test could not be sent: ' . $e->getMessage(), [], 502);
    }
    if ($result['sent'] === 0) {
        Response::error($result['failed'] > 0 ? 'The push service refused the notification. Turn notifications off and on again on this device.' : 'No device has desktop notifications turned on yet.', [], 422);
    }
    Response::success('Test sent to ' . $result['sent'] . ' device' . ($result['sent'] === 1 ? '' : 's') . '. It should appear within a few seconds.', $result);
}

Response::error('Unknown action.', [], 422);
