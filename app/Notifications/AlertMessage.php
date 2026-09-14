<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Channel-agnostic alert payload. Each notifier picks the representation it needs.
 */
final class AlertMessage
{
    public const EVENT_DOWN = 'down';
    public const EVENT_RECOVERY = 'recovery';
    public const EVENT_SSL = 'ssl_expiry';
    public const EVENT_TEST = 'test';

    public function __construct(
        public readonly string $event,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly string $telegram,
        public readonly ?int $websiteId = null,
        public readonly ?int $incidentId = null
    ) {
    }
}
