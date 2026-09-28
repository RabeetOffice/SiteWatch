<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\AlertMessage;
use App\Notifications\NotificationManager;
use App\Services\PushService;
use PHPUnit\Framework\TestCase;

/**
 * Desktop notifications: every alert event maps to a choice people can make per device.
 */
final class PushServiceTest extends TestCase
{
    public function testEveryAlertEventMapsToADeviceChoice(): void
    {
        $events = [
            AlertMessage::EVENT_DOWN, AlertMessage::EVENT_RECOVERY, AlertMessage::EVENT_SSL, AlertMessage::EVENT_WP_ERROR,
            AlertMessage::EVENT_WP_SECURITY, AlertMessage::EVENT_WP_VULNERABILITY, AlertMessage::EVENT_WP_AUTOFIX, AlertMessage::EVENT_COUNTRY,
        ];
        foreach ($events as $event) {
            self::assertArrayHasKey(PushService::eventKey($event), PushService::EVENTS, $event);
        }
        self::assertSame('test', PushService::eventKey(AlertMessage::EVENT_TEST));
    }

    public function testDesktopNotificationsAreAChannelWithALabel(): void
    {
        self::assertArrayHasKey('push', NotificationManager::CHANNEL_LABELS);
    }

    public function testTheLibraryIsInstalled(): void
    {
        self::assertTrue(PushService::available(), 'Run composer install: minishlink/web-push is required for desktop notifications.');
    }
}
