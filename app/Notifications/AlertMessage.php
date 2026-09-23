<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Channel-agnostic alert payload. Each notifier picks the representation it needs.
 *
 * The per-channel bodies are rendered once by NotificationManager so notifiers stay thin. Channels added
 * after the first release take their body from a trailing optional argument and fall back to $text, which
 * keeps older call sites (and tests) constructing this class positionally valid.
 */
final class AlertMessage
{
    public const EVENT_DOWN = 'down';
    public const EVENT_RECOVERY = 'recovery';
    public const EVENT_SSL = 'ssl_expiry';
    public const EVENT_TEST = 'test';
    public const EVENT_WP_ERROR = 'wp_error';
    public const EVENT_WP_SECURITY = 'wp_security';
    public const EVENT_WP_VULNERABILITY = 'wp_vulnerability';

    /**
     * @param string               $telegram Telegram HTML body.
     * @param string               $whatsapp WhatsApp body (*bold* / _italic_ markup, no HTML).
     * @param array<string, mixed> $discord  Embed data: title, description, color, url, fields, footer.
     */
    public function __construct(
        public readonly string $event,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly string $telegram,
        public readonly ?int $websiteId = null,
        public readonly ?int $incidentId = null,
        public readonly string $whatsapp = '',
        public readonly array $discord = []
    ) {
    }

    /** WhatsApp body, falling back to the plain-text representation. */
    public function whatsappBody(): string
    {
        return $this->whatsapp !== '' ? $this->whatsapp : $this->text;
    }
}
