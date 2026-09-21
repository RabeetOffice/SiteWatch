<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Response;
use App\Notifications\NotificationManager;
use App\Notifications\WhatsAppNotifier;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'notifications.manage');

set_time_limit(45);
$settings = App::settings();
$notifier = new WhatsAppNotifier($settings);
$phone = $notifier->recipient();

if ($phone === '') {
    Response::error('Save a destination number in international format before sending a test alert.', ['whatsapp_phone' => 'Required'], 422);
}
if ($notifier->provider() === 'callmebot') {
    if ($settings->getString('whatsapp_callmebot_apikey') === '') {
        Response::error('Save the CallMeBot API key before sending a test alert.', ['whatsapp_callmebot_apikey' => 'Required'], 422);
    }
} elseif ($settings->getString('whatsapp_cloud_phone_id') === '' || $settings->getString('whatsapp_cloud_token') === '') {
    Response::error('Save the phone number ID and access token before sending a test alert.', [], 422);
}

$manager = new NotificationManager($settings, ServiceFactory::notifications(), App::logger('notifications'));
$message = $manager->buildTestMessage('whatsapp');
try {
    $sent = $notifier->send($message);
    ServiceFactory::notifications()->log('whatsapp', 'test', 'sent', null, null, $sent, $message->subject);
    ActivityService::log('notification.test', 'Test WhatsApp alert sent to ' . $sent);
    Response::success('Test alert delivered to ' . $sent . '.');
} catch (Throwable $e) {
    ServiceFactory::notifications()->log('whatsapp', 'test', 'failed', null, null, $phone, $message->subject, $e->getMessage());
    App::logger('notifications')->error('Test WhatsApp failed', ['provider' => $notifier->provider(), 'error' => $e->getMessage()]);
    Response::error($e->getMessage(), [], 502);
}
