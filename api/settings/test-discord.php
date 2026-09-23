<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Response;
use App\Notifications\DiscordNotifier;
use App\Notifications\NotificationManager;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'notifications.manage');
// Outbound requests can take many seconds: unlock the session so the user's other requests are not queued behind this one.
App::session()->release();

set_time_limit(45);
$settings = App::settings();
$webhook = $settings->getString('discord_webhook_url');
if (!DiscordNotifier::isValidWebhook($webhook)) {
    Response::error('Save a Discord webhook URL before sending a test alert.', ['discord_webhook_url' => 'Required'], 422);
}

$manager = new NotificationManager($settings, ServiceFactory::notifications(), App::logger('notifications'));
$message = $manager->buildTestMessage('discord');
$target = DiscordNotifier::describe($webhook);
try {
    $sent = (new DiscordNotifier($settings))->send($message);
    ServiceFactory::notifications()->log('discord', 'test', 'sent', null, null, $sent, $message->subject);
    ActivityService::log('notification.test', 'Test Discord alert sent to ' . $sent);
    Response::success('Test alert posted to your Discord channel.');
} catch (Throwable $e) {
    ServiceFactory::notifications()->log('discord', 'test', 'failed', null, null, $target, $message->subject, $e->getMessage());
    App::logger('notifications')->error('Test Discord failed', ['error' => $e->getMessage()]);
    Response::error($e->getMessage(), [], 502);
}
