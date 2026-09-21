<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Repositories\SettingsRepository;
use GuzzleHttp\Client;
use RuntimeException;
use Throwable;

/**
 * Discord channel built on incoming webhooks. Webhooks are free, need no bot application and no OAuth: a
 * channel moderator creates one under Channel Settings -> Integrations and pastes the URL here.
 *
 * Alerts are posted as a single rich embed, coloured by severity, with the alert rows as embed fields.
 * Discord allows roughly 30 requests per minute per webhook and answers 429 with retry_after in seconds,
 * which is handled with one short retry so an outage burst does not silently drop an alert.
 */
final class DiscordNotifier implements NotifierInterface
{
    /**
     * Discord's own webhook hosts only. The URL is administrator-supplied and is used for a server-side
     * request, so it is pinned to the vendor rather than treated as an arbitrary endpoint.
     */
    public const WEBHOOK_PATTERN = '#^https://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api(?:/v\d{1,2})?/webhooks/\d{5,25}/[A-Za-z0-9_.-]{30,}$#';

    /** Discord message and embed limits (characters). */
    private const MAX_CONTENT = 1800;
    private const MAX_TITLE = 250;
    private const MAX_DESCRIPTION = 1500;
    private const MAX_FIELDS = 25;
    private const MAX_FIELD_NAME = 100;
    private const MAX_FIELD_VALUE = 500;
    private const MAX_FOOTER = 200;

    private Client $client;

    public function __construct(private readonly SettingsRepository $settings, ?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout'         => 12,
            'connect_timeout' => 8,
            'http_errors'     => false,
        ]);
    }

    public function name(): string
    {
        return 'discord';
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBool('discord_enabled') && self::isValidWebhook($this->settings->getString('discord_webhook_url'));
    }

    public static function isValidWebhook(string $url): bool
    {
        return $url !== '' && preg_match(self::WEBHOOK_PATTERN, $url) === 1;
    }

    /**
     * Escape Discord markdown so interpolated text cannot restyle an embed. Link syntax is escaped too:
     * diagnostics quote the monitored site's own response, and a hostile page must not be able to plant a
     * clickable link in someone's channel. Bare URLs are returned untouched — Discord applies no markup
     * inside them and the backslashes would show up literally.
     */
    public static function escapeMarkdown(string $text): string
    {
        $text = trim($text);
        if (preg_match('#^https?://\S+$#', $text) === 1) {
            return $text;
        }
        return preg_replace('/([\\\\`*_~|\[\]])/u', '\\\\$1', $text) ?? $text;
    }

    /**
     * Channel name for the delivery log. The webhook token is a credential, so only the webhook ID — the
     * part that identifies the channel without granting access to it — is ever recorded.
     */
    public static function describe(string $url): string
    {
        return preg_match('#/webhooks/(\d{5,25})/#', $url, $m) === 1 ? 'Discord webhook ' . $m[1] : 'Discord webhook';
    }

    public function send(AlertMessage $message): string
    {
        $url = $this->settings->getString('discord_webhook_url');
        if (!self::isValidWebhook($url)) {
            throw new RuntimeException('The Discord webhook URL is missing or is not a discord.com webhook address.');
        }

        $payload = $this->payload($message);
        $response = $this->post($url, $payload);

        // One retry is enough: Discord's per-webhook window is a minute and retry_after is normally under a
        // second. Anything longer is left to the next check cycle rather than holding up the cron run.
        if ($response['status'] === 429) {
            $wait = min(5.0, max(0.5, (float) ($response['body']['retry_after'] ?? 1)));
            usleep((int) round($wait * 1_000_000));
            $response = $this->post($url, $payload);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Discord webhook error: ' . self::describeError($response['status'], $response['body'], $response['raw'], $url));
        }
        return self::describe($url);
    }

    // ------------------------------------------------------------------
    // Request
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>, raw: string}
     */
    private function post(string $url, array $payload): array
    {
        try {
            $response = $this->client->post($url, ['json' => $payload]);
        } catch (Throwable $e) {
            throw new RuntimeException('Discord request failed: ' . self::redact($e->getMessage(), $url));
        }
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        return [
            'status' => $response->getStatusCode(),
            'body'   => is_array($decoded) ? $decoded : [],
            'raw'    => $raw,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(AlertMessage $message): array
    {
        $mention = trim($this->settings->getString('discord_mention'));
        $content = trim($mention . ' ' . $message->subject);

        $payload = [
            'content'          => mb_substr($content, 0, self::MAX_CONTENT),
            'embeds'           => [self::embed($message)],
            'allowed_mentions' => self::allowedMentions($mention),
        ];
        $username = trim($this->settings->getString('discord_username'));
        if ($username !== '') {
            $payload['username'] = mb_substr($username, 0, 80);
        }
        return $payload;
    }

    /**
     * Only the mention the administrator configured is allowed to ping. Without this Discord would resolve
     * any @-looking text that happens to appear in a website name or an error message.
     *
     * @return array<string, mixed>
     */
    private static function allowedMentions(string $mention): array
    {
        if ($mention === '@here' || $mention === '@everyone') {
            return ['parse' => ['everyone']];
        }
        if (preg_match('/^<@&(\d{5,25})>$/', $mention, $m) === 1) {
            return ['parse' => [], 'roles' => [$m[1]]];
        }
        return ['parse' => []];
    }

    /**
     * Build the embed from the structured payload NotificationManager prepared, trimmed to Discord's limits.
     *
     * @return array<string, mixed>
     */
    private static function embed(AlertMessage $message): array
    {
        $source = $message->discord;
        $embed = [
            'title'       => mb_substr((string) ($source['title'] ?? $message->subject), 0, self::MAX_TITLE),
            'description' => mb_substr((string) ($source['description'] ?? $message->text), 0, self::MAX_DESCRIPTION),
            'color'       => (int) ($source['color'] ?? 0xC2410C),
            'timestamp'   => utc_now()->format('c'),
        ];

        $url = (string) ($source['url'] ?? '');
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            $embed['url'] = $url;
        }
        $footer = trim((string) ($source['footer'] ?? ''));
        if ($footer !== '') {
            $embed['footer'] = ['text' => mb_substr($footer, 0, self::MAX_FOOTER)];
        }

        $fields = [];
        foreach (is_array($source['fields'] ?? null) ? $source['fields'] : [] as $field) {
            if (!is_array($field) || !isset($field['name'], $field['value'])) {
                continue;
            }
            $value = trim((string) $field['value']);
            $fields[] = [
                'name'   => mb_substr((string) $field['name'], 0, self::MAX_FIELD_NAME),
                'value'  => mb_substr($value === '' ? '—' : $value, 0, self::MAX_FIELD_VALUE),
                'inline' => (bool) ($field['inline'] ?? false),
            ];
            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }
        }
        if ($fields !== []) {
            $embed['fields'] = $fields;
        }
        return $embed;
    }

    // ------------------------------------------------------------------
    // Errors
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private static function describeError(int $status, array $body, string $raw, string $url): string
    {
        if ($status === 401 || $status === 403 || $status === 404) {
            return 'HTTP ' . $status . ' — the webhook no longer exists or was revoked in Discord. Create a new one and paste the new URL.';
        }
        if ($status === 429) {
            return 'HTTP 429 — this webhook is being rate limited by Discord (about 30 messages per minute).';
        }
        $detail = isset($body['message']) && is_scalar($body['message']) ? (string) $body['message'] : self::redact($raw, $url);
        return 'HTTP ' . $status . ($detail !== '' ? ': ' . mb_substr(trim($detail), 0, 300) : '.');
    }

    /** Keep the webhook token — which is the credential — out of logs and the interface. */
    private static function redact(string $text, string $url): string
    {
        if (preg_match('#/webhooks/\d{5,25}/([A-Za-z0-9_.-]{30,})#', $url, $m) === 1) {
            $text = str_replace($m[1], '[token]', $text);
        }
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, 300);
    }
}
