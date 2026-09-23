<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Core\App;
use App\Monitoring\CheckResult;
use App\Monitoring\Status;
use App\Repositories\NotificationRepository;
use App\Repositories\SettingsRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Central dispatcher: builds alert messages, applies alert rules (global + per-website), sends via
 * every enabled channel and logs each attempt. Deduplication is enforced by the incident lifecycle
 * (notified_at / recovery_notified_at) and by SSL alert levels on the website row.
 */
final class NotificationManager
{
    /** Display name per channel key, used in test alerts and in the delivery history. */
    public const CHANNEL_LABELS = [
        'email'    => 'Email',
        'telegram' => 'Telegram',
        'whatsapp' => 'WhatsApp',
        'discord'  => 'Discord',
        'none'     => 'No channel',
    ];

    /** Embed accent per severity, matching the colours used in the HTML email layout. */
    private const DISCORD_COLORS = [
        'danger'  => 0xDC2626,
        'success' => 0x16A34A,
        'warning' => 0xD97706,
        'info'    => 0x2563EB,
    ];

    /** @var array<int, NotifierInterface>|null */
    private ?array $notifiers = null;

    /**
     * @param array<int, NotifierInterface>|null $notifiers Override channels (tests).
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly NotificationRepository $log,
        private readonly LoggerInterface $logger,
        ?array $notifiers = null
    ) {
        $this->notifiers = $notifiers;
    }

    /** @return array<int, NotifierInterface> */
    public function notifiers(): array
    {
        if ($this->notifiers === null) {
            $this->notifiers = [
                new EmailNotifier($this->settings),
                new TelegramNotifier($this->settings),
                new WhatsAppNotifier($this->settings),
                new DiscordNotifier($this->settings),
            ];
        }
        return $this->notifiers;
    }

    /**
     * True when at least one channel (email, Telegram, WhatsApp, Discord) is switched on.
     */
    public function hasEnabledChannel(): bool
    {
        foreach ($this->notifiers() as $notifier) {
            if ($notifier->isEnabled()) {
                return true;
            }
        }
        return false;
    }

    public function notifier(string $name): ?NotifierInterface
    {
        foreach ($this->notifiers() as $notifier) {
            if ($notifier->name() === $name) {
                return $notifier;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Alert rules
    // ------------------------------------------------------------------

    /**
     * Global rule (alert_<key>) AND per-website override (alerts_json).
     *
     * @param array<string, mixed> $website
     */
    public function isAlertEnabled(array $website, string $alertKey): bool
    {
        if (!$this->settings->getBool('alert_' . $alertKey, true)) {
            return false;
        }
        $siteKey = match ($alertKey) {
            'critical', 'database' => 'critical',
            'slow'                 => 'slow',
            'ssl'                  => 'ssl',
            'recovery'             => 'recovery',
            default                => 'down',
        };
        $raw = $website['alerts_json'] ?? null;
        $alerts = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (is_array($alerts) && array_key_exists($siteKey, $alerts)) {
            return (bool) $alerts[$siteKey];
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $incident
     */
    public function incidentOpened(array $website, array $incident, CheckResult $result): bool
    {
        $alertKey = Status::alertKey($result->status);
        if (!$this->isAlertEnabled($website, $alertKey)) {
            $this->log->log('none', AlertMessage::EVENT_DOWN, 'skipped', (int) $website['id'], (int) $incident['id'], null, null, "Alert type '{$alertKey}' is disabled for this website.");
            return false;
        }
        $message = $this->buildDownMessage($website, $incident, $result);
        return $this->dispatch($message, (int) $website['id'], (int) $incident['id']);
    }

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $incident
     */
    public function incidentResolved(array $website, array $incident, CheckResult $result): bool
    {
        if (!$this->isAlertEnabled($website, 'recovery')) {
            $this->log->log('none', AlertMessage::EVENT_RECOVERY, 'skipped', (int) $website['id'], (int) $incident['id'], null, null, 'Recovery alerts are disabled for this website.');
            return false;
        }
        $message = $this->buildRecoveryMessage($website, $incident, $result);
        return $this->dispatch($message, (int) $website['id'], (int) $incident['id']);
    }

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $sslInfo
     */
    public function sslExpiry(array $website, ?int $daysRemaining, array $sslInfo): bool
    {
        if (!$this->isAlertEnabled($website, 'ssl')) {
            $this->log->log('none', AlertMessage::EVENT_SSL, 'skipped', (int) $website['id'], null, null, null, 'SSL alerts are disabled for this website.');
            return false;
        }
        $message = $this->buildSslMessage($website, $daysRemaining, $sslInfo);
        return $this->dispatch($message, (int) $website['id'], null);
    }

    /**
     * Send a test alert through one channel.
     *
     * @return array{success: bool, message: string}
     */
    public function sendTest(string $channel): array
    {
        $notifier = $this->notifier($channel);
        if ($notifier === null) {
            return ['success' => false, 'message' => 'Unknown notification channel.'];
        }
        $message = $this->buildTestMessage($channel);
        try {
            $recipient = $notifier->send($message);
            $this->log->log($channel, AlertMessage::EVENT_TEST, 'sent', null, null, $recipient, $message->subject);
            return ['success' => true, 'message' => 'Test notification sent to ' . $recipient . '.'];
        } catch (Throwable $e) {
            $this->log->log($channel, AlertMessage::EVENT_TEST, 'failed', null, null, null, $message->subject, $e->getMessage());
            $this->logger->error('Test notification failed', ['channel' => $channel, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function dispatch(AlertMessage $message, ?int $websiteId, ?int $incidentId): bool
    {
        $sent = false;
        $anyEnabled = false;
        foreach ($this->notifiers() as $notifier) {
            if (!$notifier->isEnabled()) {
                continue;
            }
            $anyEnabled = true;
            try {
                $recipient = $notifier->send($message);
                $this->log->log($notifier->name(), $message->event, 'sent', $websiteId, $incidentId, $recipient, $message->subject);
                $this->logger->info('Notification sent', ['channel' => $notifier->name(), 'event' => $message->event, 'website_id' => $websiteId, 'recipient' => $recipient]);
                $sent = true;
            } catch (Throwable $e) {
                $this->log->log($notifier->name(), $message->event, 'failed', $websiteId, $incidentId, null, $message->subject, $e->getMessage());
                $this->logger->error('Notification failed', ['channel' => $notifier->name(), 'event' => $message->event, 'website_id' => $websiteId, 'error' => $e->getMessage()]);
            }
        }
        if (!$anyEnabled) {
            $this->log->log('none', $message->event, 'skipped', $websiteId, $incidentId, null, $message->subject, 'No notification channel is enabled.');
            $this->logger->notice('Notification skipped: no channel enabled', ['event' => $message->event, 'website_id' => $websiteId]);
        }
        return $sent;
    }

    // ------------------------------------------------------------------
    // Message builders
    // ------------------------------------------------------------------

    private function appUrl(): string
    {
        $url = $this->settings->getString('app_url');
        if ($url === '') {
            try {
                $url = (string) App::config()->get('app.url', '');
            } catch (Throwable) {
                $url = '';
            }
        }
        return rtrim($url, '/');
    }

    private function detailsUrl(int $websiteId): string
    {
        $base = $this->appUrl();
        return $base !== '' ? $base . '/admin/website-details.php?id=' . $websiteId : '';
    }

    private function appName(): string
    {
        return $this->settings->getString('app_name', 'SiteWatch') ?: 'SiteWatch';
    }

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $incident
     */
    public function buildDownMessage(array $website, array $incident, CheckResult $result): AlertMessage
    {
        $name = (string) $website['name'];
        $issue = (string) ($incident['title'] ?? Status::label($result->status));
        $subject = 'Website Down — ' . $name;
        if ($result->status !== Status::DOWN) {
            $subject = $issue . ' — ' . $name;
        }
        $detected = format_datetime($incident['started_at'] ?? $result->checkedAt);
        $http = $result->httpStatus !== null ? (string) $result->httpStatus : 'n/a';
        $response = $result->responseTime !== null ? format_ms($result->responseTime) : 'n/a';
        $diag = (string) ($result->errorMessage ?? '');

        $rows = [
            ['Website', $name],
            ['Client', (string) ($website['client_name'] ?: '—')],
            ['URL', (string) $website['url']],
            ['Issue', $issue],
            ['HTTP Status', $http],
            ['Response Time', $response],
            ['Detected', $detected],
            ['Diagnostics', $diag !== '' ? $diag : '—'],
        ];

        $text = "{$subject}\n\n" . $this->textRows($rows) . "\n\nOpen website details: " . $this->detailsUrl((int) $website['id']);
        $html = $this->htmlLayout($subject, $issue, 'danger', $rows, $this->detailsUrl((int) $website['id']), 'Open Website Details');
        $detectedAt = format_datetime($incident['started_at'] ?? $result->checkedAt, 'g:i A');
        $telegram = "🚨 <b>Website Down</b>\n\n<b>" . self::tg($name) . "</b>\n" . self::tg($issue)
            . "\n\nHTTP: " . self::tg($http) . "\nResponse: " . self::tg($response) . "\nDetected: " . self::tg($detectedAt)
            . ($diag !== '' ? "\n\n<i>" . self::tg(str_limit($diag, 300)) . "</i>" : '')
            . "\n\n" . self::tg((string) $website['url']);
        $whatsapp = "🚨 *Website Down*\n\n*" . self::wa($name) . "*\n" . self::wa($issue)
            . "\n\nHTTP: " . self::wa($http) . "\nResponse: " . self::wa($response) . "\nDetected: " . self::wa($detectedAt)
            . ($diag !== '' ? "\n\n_" . self::wa(str_limit($diag, 300)) . "_" : '')
            . "\n\n" . self::wa((string) $website['url']);
        $discord = $this->discordPayload($subject, '🚨 ' . $issue, 'danger', $rows, (string) $website['url'], (int) $website['id']);

        return new AlertMessage(AlertMessage::EVENT_DOWN, $subject, $text, $html, $telegram, (int) $website['id'], (int) $incident['id'], $whatsapp, $discord);
    }

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $incident
     */
    public function buildRecoveryMessage(array $website, array $incident, CheckResult $result): AlertMessage
    {
        $name = (string) $website['name'];
        $subject = 'Website Recovered — ' . $name;
        $duration = format_duration((int) ($incident['duration_seconds'] ?? 0));
        $rows = [
            ['Website', $name],
            ['Client', (string) ($website['client_name'] ?: '—')],
            ['URL', (string) $website['url']],
            ['Recovered At', format_datetime($incident['resolved_at'] ?? $result->checkedAt)],
            ['Total Downtime', $duration],
            ['Original Incident', (string) ($incident['title'] ?? '') . (isset($incident['started_at']) ? ' (started ' . format_datetime($incident['started_at']) . ')' : '')],
            ['Current HTTP Status', $result->httpStatus !== null ? (string) $result->httpStatus : 'n/a'],
            ['Current Response Time', $result->responseTime !== null ? format_ms($result->responseTime) : 'n/a'],
        ];
        $text = "{$subject}\n\n" . $this->textRows($rows) . "\n\nOpen website details: " . $this->detailsUrl((int) $website['id']);
        $html = $this->htmlLayout($subject, 'Back online', 'success', $rows, $this->detailsUrl((int) $website['id']), 'Open Website Details');
        $http = $result->httpStatus !== null ? (string) $result->httpStatus : 'n/a';
        $response = $result->responseTime !== null ? format_ms($result->responseTime) : 'n/a';
        $telegram = "✅ <b>Website Recovered</b>\n\n<b>" . self::tg($name) . "</b>\nDowntime: " . self::tg($duration)
            . "\n\nHTTP: " . self::tg($http)
            . "\nResponse: " . self::tg($response)
            . "\n\n" . self::tg((string) $website['url']);
        $whatsapp = "✅ *Website Recovered*\n\n*" . self::wa($name) . "*\nDowntime: " . self::wa($duration)
            . "\n\nHTTP: " . self::wa($http) . "\nResponse: " . self::wa($response)
            . "\n\n" . self::wa((string) $website['url']);
        $discord = $this->discordPayload($subject, '✅ Back online after ' . $duration, 'success', $rows, (string) $website['url'], (int) $website['id']);

        return new AlertMessage(AlertMessage::EVENT_RECOVERY, $subject, $text, $html, $telegram, (int) $website['id'], (int) $incident['id'], $whatsapp, $discord);
    }

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed> $sslInfo
     */
    public function buildSslMessage(array $website, ?int $daysRemaining, array $sslInfo): AlertMessage
    {
        $name = (string) $website['name'];
        $expired = $daysRemaining !== null && $daysRemaining < 0;
        $headline = $expired ? 'SSL Certificate Expired' : ($daysRemaining !== null && $daysRemaining <= 7 ? 'SSL Certificate Expiring Soon' : 'SSL Certificate Expiring');
        $subject = $headline . ' — ' . $name;
        $remaining = $daysRemaining === null ? 'unknown' : ($expired ? 'expired ' . abs($daysRemaining) . ' day(s) ago' : $daysRemaining . ' day(s) remaining');
        $rows = [
            ['Website', $name],
            ['Client', (string) ($website['client_name'] ?: '—')],
            ['URL', (string) $website['url']],
            ['Certificate Expiry', format_datetime($sslInfo['expires_at'] ?? null)],
            ['Days Remaining', $remaining],
            ['Issuer', (string) ($sslInfo['issuer'] ?? '—')],
            ['Status', $sslInfo['valid'] ? 'Valid' : ('Invalid — ' . ($sslInfo['error'] ?? ''))],
        ];
        $text = "{$subject}\n\n" . $this->textRows($rows) . "\n\nOpen website details: " . $this->detailsUrl((int) $website['id']);
        $html = $this->htmlLayout($subject, $headline, $expired ? 'danger' : 'warning', $rows, $this->detailsUrl((int) $website['id']), 'Open Website Details');
        $icon = $expired ? '🔴' : '⚠️';
        $expiresAt = format_datetime($sslInfo['expires_at'] ?? null);
        $telegram = $icon . " <b>" . self::tg($headline) . "</b>\n\n<b>" . self::tg($name) . "</b>\n" . self::tg($remaining)
            . "\nExpires: " . self::tg($expiresAt) . "\n\n" . self::tg((string) $website['url']);
        $whatsapp = $icon . " *" . self::wa($headline) . "*\n\n*" . self::wa($name) . "*\n" . self::wa($remaining)
            . "\nExpires: " . self::wa($expiresAt) . "\n\n" . self::wa((string) $website['url']);
        $discord = $this->discordPayload($subject, $icon . ' ' . $headline . ' — ' . $remaining, $expired ? 'danger' : 'warning', $rows, (string) $website['url'], (int) $website['id']);

        return new AlertMessage(AlertMessage::EVENT_SSL, $subject, $text, $html, $telegram, (int) $website['id'], null, $whatsapp, $discord);
    }

    public function buildTestMessage(string $channel): AlertMessage
    {
        $subject = $this->appName() . ' test notification';
        $label = self::CHANNEL_LABELS[$channel] ?? ucfirst($channel);
        $sentAt = format_datetime(utc_now()->format('Y-m-d H:i:s'));
        $rows = [
            ['Channel', $label],
            ['Sent At', $sentAt],
            ['Application', $this->appName()],
        ];
        $confirmation = 'If you can read this, ' . $label . ' alerts are configured correctly.';
        $text = "{$subject}\n\nThis is a test notification. {$confirmation}\n\n" . $this->textRows($rows);
        $html = $this->htmlLayout($subject, 'Test notification', 'info', $rows, $this->appUrl(), 'Open ' . $this->appName(), 'This is a test notification. ' . e($confirmation));
        $telegram = "🔔 <b>" . self::tg($subject) . "</b>\n\n" . self::tg($confirmation) . "\n" . self::tg($sentAt);
        $whatsapp = "🔔 *" . self::wa($subject) . "*\n\n" . self::wa($confirmation) . "\n" . self::wa($sentAt);
        $discord = $this->discordPayload($subject, '🔔 ' . $confirmation, 'info', $rows, $this->appUrl());
        return new AlertMessage(AlertMessage::EVENT_TEST, $subject, $text, $html, $telegram, null, null, $whatsapp, $discord);
    }

    // ------------------------------------------------------------------
    // Formatting helpers
    // ------------------------------------------------------------------

    /** @param array<int, array{0: string, 1: string}> $rows */
    private function textRows(array $rows): string
    {
        $lines = [];
        foreach ($rows as [$label, $value]) {
            $lines[] = str_pad($label . ':', 22) . $value;
        }
        return implode("\n", $lines);
    }

    /** @param array<int, array{0: string, 1: string}> $rows */
    private function htmlLayout(string $subject, string $badge, string $tone, array $rows, string $ctaUrl, string $ctaLabel, string $intro = ''): string
    {
        $colors = [
            'danger'  => '#DC2626',
            'success' => '#16A34A',
            'warning' => '#D97706',
            'info'    => '#2563EB',
        ];
        $color = $colors[$tone] ?? '#C2410C';
        $rowsHtml = '';
        foreach ($rows as [$label, $value]) {
            $rowsHtml .= '<tr><td style="padding:9px 12px;border-bottom:1px solid #E5E7EB;color:#64748B;font-size:13px;width:38%;vertical-align:top;">' . e($label) . '</td>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #E5E7EB;color:#0F172A;font-size:13px;word-break:break-word;">' . e($value) . '</td></tr>';
        }
        $cta = $ctaUrl !== ''
            ? '<p style="margin:24px 0 0;"><a href="' . e($ctaUrl) . '" style="display:inline-block;background:#C2410C;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 20px;border-radius:8px;">' . e($ctaLabel) . '</a></p>'
            : '';
        $introHtml = $intro !== '' ? '<p style="margin:0 0 16px;color:#334155;font-size:14px;line-height:1.5;">' . $intro . '</p>' : '';

        return '<!doctype html><html><body style="margin:0;padding:24px;background:#F8FAFC;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
            . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">'
            . '<div style="padding:20px 24px;border-bottom:1px solid #E5E7EB;display:flex;align-items:center;">'
            . '<span style="font-size:16px;font-weight:700;color:#0F172A;">' . e($this->appName()) . '</span></div>'
            . '<div style="padding:24px;">'
            . '<span style="display:inline-block;background:' . $color . ';color:#fff;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:4px 10px;border-radius:6px;">' . e($badge) . '</span>'
            . '<h1 style="font-size:20px;line-height:1.3;margin:14px 0 18px;color:#0F172A;">' . e($subject) . '</h1>'
            . $introHtml
            . '<table cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #E5E7EB;border-radius:10px;border-collapse:separate;overflow:hidden;">' . $rowsHtml . '</table>'
            . $cta
            . '</div>'
            . '<div style="padding:14px 24px;background:#F8FAFC;border-top:1px solid #E5E7EB;color:#94A3B8;font-size:12px;">Sent by ' . e($this->appName()) . ' website monitoring.</div>'
            . '</div></body></html>';
    }

    private static function tg(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * WhatsApp has no escape syntax for its *bold* / _italic_ markup, so only control characters — which
     * come from error bodies and would otherwise be sent verbatim — are removed.
     */
    private static function wa(string $text): string
    {
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text);
    }

    /**
     * Structured embed for the Discord channel. The rows shown in the email become embed fields, so every
     * channel reports the same facts; DiscordNotifier applies Discord's own length limits.
     *
     * @param array<int, array{0: string, 1: string}> $rows
     *
     * @return array<string, mixed>
     */
    private function discordPayload(string $title, string $headline, string $tone, array $rows, string $url, ?int $websiteId = null): array
    {
        $details = $websiteId !== null ? $this->detailsUrl($websiteId) : $this->appUrl();
        $description = DiscordNotifier::escapeMarkdown($headline);
        if ($details !== '') {
            $description .= "\n[Open in " . DiscordNotifier::escapeMarkdown($this->appName()) . '](' . $details . ')';
        }

        $fields = [];
        foreach ($rows as [$label, $value]) {
            $value = trim($value);
            $fields[] = [
                'name'   => $label,
                'value'  => $value === '' ? '—' : DiscordNotifier::escapeMarkdown($value),
                'inline' => mb_strlen($value) <= 32,
            ];
        }

        return [
            'title'       => $title,
            'description' => $description,
            'color'       => self::DISCORD_COLORS[$tone] ?? self::DISCORD_COLORS['info'],
            'url'         => $url,
            'fields'      => $fields,
            'footer'      => 'Sent by ' . $this->appName() . ' website monitoring',
        ];
    }
}
