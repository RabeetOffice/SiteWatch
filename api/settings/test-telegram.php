<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Response;
use App\Notifications\NotificationManager;
use App\Notifications\TelegramNotifier;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'notifications.manage');

set_time_limit(45);
$settings = App::settings();
if ($settings->getString('telegram_bot_token') === '' || $settings->getString('telegram_chat_id') === '') {
    Response::error('Save the bot token and chat ID before sending a test alert.', [], 422);
}

$manager = new NotificationManager($settings, ServiceFactory::notifications(), App::logger('notifications'));
$message = $manager->buildTestMessage('telegram');
try {
    $sent = (new TelegramNotifier($settings))->send($message);
    ServiceFactory::notifications()->log('telegram', 'test', 'sent', null, null, $sent, $message->subject);
    ActivityService::log('notification.test', 'Test Telegram alert sent to ' . $sent);
    Response::success('Test alert delivered to ' . $sent . '.');
} catch (Throwable $e) {
    ServiceFactory::notifications()->log('telegram', 'test', 'failed', null, null, $settings->getString('telegram_chat_id'), $message->subject, $e->getMessage());
    App::logger('notifications')->error('Test Telegram failed', ['error' => $e->getMessage()]);
    Response::error($e->getMessage(), [], 502);
}
