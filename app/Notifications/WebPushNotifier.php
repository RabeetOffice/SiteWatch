<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\PushService;
use RuntimeException;
use Throwable;

/**
 * Desktop notifications (Web Push) as an alert channel, next to email, Telegram, WhatsApp and Discord.
 * It is switched on by people turning notifications on in their browser, not by a setting.
 */
final class WebPushNotifier implements NotifierInterface
{
    private ?bool $enabled = null;

    public function __construct(private readonly ?PushService $push = null)
    {
    }

    public function name(): string
    {
        return 'push';
    }

    public function isEnabled(): bool
    {
        if ($this->enabled === null) {
            try {
                $this->enabled = PushService::available() && $this->service()->hasSubscribers();
            } catch (Throwable) {
                // Database not updated to 2.0 yet, or the package is missing.
                $this->enabled = false;
            }
        }
        return $this->enabled;
    }

    public function send(AlertMessage $message): string
    {
        $result = $this->service()->sendAlert($message);
        if ($result['sent'] === 0 && $result['failed'] === 0) {
            return 'no device chose this kind of alert';
        }
        if ($result['sent'] === 0 && $result['failed'] > 0) {
            throw new RuntimeException('No device accepted the notification (' . $result['failed'] . ' failed' . ($result['removed'] ? ', ' . $result['removed'] . ' expired and removed' : '') . ').');
        }
        return $result['sent'] . ' device' . ($result['sent'] === 1 ? '' : 's');
    }

    private function service(): PushService
    {
        return $this->push ?? PushService::create();
    }
}
