<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * A notification channel. Additional channels (Slack, Discord, WhatsApp...) implement this
 * interface and are registered in NotificationManager::notifiers().
 */
interface NotifierInterface
{
    /** Channel key used in logs and settings, e.g. "email", "telegram". */
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
