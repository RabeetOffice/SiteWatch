<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Response;
use App\Notifications\EmailNotifier;
use App\Notifications\NotificationManager;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'notifications.manage');

set_time_limit(60);
$settings = App::settings();
if ($settings->getString('smtp_host') === '') {
    Response::error('Configure and save the SMTP host before sending a test email.', ['smtp_host' => 'Required'], 422);
}
$recipients = (new EmailNotifier($settings))->recipients();
if ($recipients === []) {
    // Fall back to the signed-in administrator so the test can still be delivered.
    $recipients = [(string) App::auth()->user()['email']];
}

$manager = new NotificationManager($settings, ServiceFactory::notifications(), App::logger('notifications'));
$message = $manager->buildTestMessage('email');
try {
    $sent = (new EmailNotifier($settings))->deliver($message, $recipients);
    ServiceFactory::notifications()->log('email', 'test', 'sent', null, null, $sent, $message->subject);
    ActivityService::log('notification.test', 'Test email sent to ' . $sent);
    Response::success('Test email sent to ' . $sent . '.', ['recipients' => $recipients]);
} catch (Throwable $e) {
    ServiceFactory::notifications()->log('email', 'test', 'failed', null, null, implode(', ', $recipients), $message->subject, $e->getMessage());
    App::logger('notifications')->error('Test email failed', ['error' => $e->getMessage()]);
    Response::error($e->getMessage(), [], 502);
}
