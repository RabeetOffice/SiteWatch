<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * A notification channel. Further channels (Slack, Microsoft Teams, SMS...) implement this interface,
 * are registered in NotificationManager::notifiers() and render their body in its message builders.
 */
interface NotifierInterface
{
    /** Channel key used in logs and settings, e.g. "email", "whatsapp". */
    public function name(): string;

    /** Whether the administrator has enabled and configured this channel. */
    public function isEnabled(): bool;

    /**
     * Deliver the message. Must throw on failure.
     *
     * @return string Human readable recipient description (logged).
     */
    public function send(AlertMessage $message): string;
}
