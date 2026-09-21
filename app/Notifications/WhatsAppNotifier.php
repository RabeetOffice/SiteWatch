<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Repositories\SettingsRepository;
use GuzzleHttp\Client;
use RuntimeException;
use Throwable;

/**
 * WhatsApp channel with three providers, none of which requires a paid subscription:
 *
 *  - green_api  The default. GREEN-API's Developer plan is free with no card and no expiry; it links the
 *               administrator's own WhatsApp by QR code and sends from that number, so there are no message
 *               templates and no business profile to set up. The free plan talks to three chats, which is
 *               ample for alerting. It bridges WhatsApp Web rather than using an official API, so the linked
 *               number carries a small risk of being restricted by WhatsApp if it is used to send in bulk.
 *  - cloud_api  Meta's own WhatsApp Cloud API: the official route, and the one with no account risk. A test
 *               number is free and needs no card, but reaches at most five verified recipients. Alerts are
 *               business-initiated, so they fall outside the 24-hour service window and need an approved
 *               template — supply its name and the alert is passed as body parameter {{1}}. Without a
 *               template a plain text message is sent, which only reaches someone who messaged you recently.
 *  - callmebot  No account and no cost, but the administrator must authorise the CallMeBot contact from
 *               their own phone and wait for an API key by reply. That activation bot is run as a hobby
 *               service and regularly answers nothing at all, so this is kept as a fallback rather than the
 *               default. It can only ever deliver to the number that authorised it, for personal use only.
 */
final class WhatsAppNotifier implements NotifierInterface
{
    public const PROVIDERS = ['green_api', 'cloud_api', 'callmebot'];
    public const DEFAULT_PROVIDER = 'green_api';

    /** Graph API version used by the Cloud API provider. */
    private const GRAPH_VERSION = 'v23.0';

    /** Host GREEN-API serves from when the console has not given the account its own. */
    public const GREEN_DEFAULT_URL = 'https://api.green-api.com';

    /**
     * GREEN-API hands newer accounts a numbered host of their own, e.g. https://7103.api.greenapi.com.
     * Every label before the domain must end in a real dot, so a look-alike such as evilgreen-api.com
     * cannot pass as a subdomain.
     */
    public const GREEN_URL_PATTERN = '#^https://(?:[A-Za-z0-9-]+\.)*green-?api\.com$#';

    /** CallMeBot is a GET endpoint, so the message has to survive inside a URL. */
    private const CALLMEBOT_MAX_CHARS = 900;

    /** WhatsApp caps a text body at 4096 characters. */
    private const CLOUD_MAX_CHARS = 3900;

    /** GREEN-API accepts 20,000, but an alert never needs more than a WhatsApp message body. */
    private const GREEN_MAX_CHARS = 3900;

    private Client $client;

    public function __construct(private readonly SettingsRepository $settings, ?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout'         => 15,
            'connect_timeout' => 8,
            'http_errors'     => false,
        ]);
    }

    public function name(): string
    {
        return 'whatsapp';
    }

    public function provider(): string
    {
        $provider = $this->settings->getString('whatsapp_provider', self::DEFAULT_PROVIDER);
        return in_array($provider, self::PROVIDERS, true) ? $provider : self::DEFAULT_PROVIDER;
    }

    public function isEnabled(): bool
    {
        if (!$this->settings->getBool('whatsapp_enabled') || $this->recipient() === '') {
            return false;
        }
        return match ($this->provider()) {
            'green_api' => $this->settings->getString('whatsapp_green_instance') !== '' && $this->settings->getString('whatsapp_green_token') !== '',
            'cloud_api' => $this->settings->getString('whatsapp_cloud_phone_id') !== '' && $this->settings->getString('whatsapp_cloud_token') !== '',
            default     => $this->settings->getString('whatsapp_callmebot_apikey') !== '',
        };
    }

    /** Destination number in E.164 form with a leading "+", or an empty string when unset or malformed. */
    public function recipient(): string
    {
        return self::normalizePhone($this->settings->getString('whatsapp_phone'));
    }

    /**
     * Reduce a phone number to E.164 ("+" followed by 7 to 15 digits). Spaces, dashes, dots and brackets are
     * common in pasted numbers and are removed; anything else is rejected.
     */
    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/[\s().\-]/', '', trim($raw)) ?? '';
        if (!preg_match('/^\+?[1-9]\d{6,14}$/', $digits)) {
            return '';
        }
        return '+' . ltrim($digits, '+');
    }

    public function send(AlertMessage $message): string
    {
        $phone = $this->recipient();
        if ($phone === '') {
            throw new RuntimeException('The WhatsApp destination number is missing or is not in international format.');
        }
        return match ($this->provider()) {
            'green_api' => $this->sendViaGreenApi($phone, $message->whatsappBody()),
            'cloud_api' => $this->sendViaCloudApi($phone, $message->whatsappBody()),
            default     => $this->sendViaCallMeBot($phone, $message->whatsappBody()),
        };
    }

    /** GREEN-API addresses a personal contact as the number's digits followed by "@c.us". */
    public static function greenChatId(string $phone): string
    {
        return ltrim($phone, '+') . '@c.us';
    }

    /** The console gives each account a host; only GREEN-API's own hosts are accepted. */
    public static function greenApiUrl(string $configured): string
    {
        $url = rtrim(trim($configured), '/');
        return $url !== '' && preg_match(self::GREEN_URL_PATTERN, $url) === 1 ? $url : self::GREEN_DEFAULT_URL;
    }

    // ------------------------------------------------------------------
    // Providers
    // ------------------------------------------------------------------

    private function sendViaCallMeBot(string $phone, string $body): string
    {
        $apiKey = $this->settings->getString('whatsapp_callmebot_apikey');
        if ($apiKey === '') {
            throw new RuntimeException('The CallMeBot API key is not configured.');
        }

        try {
            $response = $this->client->get('https://api.callmebot.com/whatsapp.php', [
                'query' => [
                    'phone'  => $phone,
                    'text'   => mb_substr($body, 0, self::CALLMEBOT_MAX_CHARS),
                    'apikey' => $apiKey,
                ],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('CallMeBot request failed: ' . self::sanitize($e->getMessage(), [$apiKey]));
        }

        $status = $response->getStatusCode();
        $reply = self::plain((string) $response->getBody());
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('CallMeBot returned HTTP ' . $status . ($reply !== '' ? ': ' . self::sanitize($reply, [$apiKey]) : '.'));
        }
        // CallMeBot answers 200 with a short human sentence either way ("Message queued..." or an
        // explanation of what went wrong), so the opening of the reply decides whether it was accepted.
        if (preg_match('/\b(error|invalid|not allowed|unauthori[sz]ed|missing|expired|failed|activate)\b/i', mb_substr($reply, 0, 200))) {
            throw new RuntimeException('CallMeBot rejected the message: ' . self::sanitize($reply, [$apiKey]));
        }
        return 'WhatsApp ' . $phone;
    }

    private function sendViaGreenApi(string $phone, string $body): string
    {
        $instance = $this->settings->getString('whatsapp_green_instance');
        $token = $this->settings->getString('whatsapp_green_token');
        if ($instance === '' || $token === '') {
            throw new RuntimeException('The GREEN-API instance ID and API token are both required.');
        }

        $endpoint = self::greenApiUrl($this->settings->getString('whatsapp_green_api_url'))
            . '/waInstance' . rawurlencode($instance) . '/sendMessage/' . rawurlencode($token);

        try {
            $response = $this->client->post($endpoint, [
                'json' => [
                    'chatId'      => self::greenChatId($phone),
                    'message'     => mb_substr($body, 0, self::GREEN_MAX_CHARS),
                    'linkPreview' => false,
                ],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('GREEN-API request failed: ' . self::sanitize($e->getMessage(), [$token]));
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('GREEN-API error: ' . self::sanitize(self::greenError($status, $decoded, (string) $response->getBody()), [$token]));
        }
        if (!isset($decoded['idMessage'])) {
            throw new RuntimeException('GREEN-API did not confirm that the message was accepted. Check that the instance is authorised in the console.');
        }
        return 'WhatsApp ' . $phone;
    }

    /**
     * GREEN-API's failures are mostly about the instance rather than the message, so they are translated
     * into the thing the administrator actually has to go and fix.
     *
     * @param array<string, mixed> $decoded
     */
    private static function greenError(int $status, array $decoded, string $raw): string
    {
        if ($status === 401 || $status === 403) {
            return 'HTTP ' . $status . ' — the instance ID or API token is wrong, or the instance was deleted in the GREEN-API console.';
        }
        if ($status === 466) {
            return 'HTTP 466 — the free Developer plan allows three chats and a monthly message quota; this number is outside it, or the quota is used up.';
        }
        foreach (['message', 'error', 'reason', 'description'] as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key]) && (string) $decoded[$key] !== '') {
                return 'HTTP ' . $status . ': ' . (string) $decoded[$key];
            }
        }
        return 'HTTP ' . $status . ($raw !== '' ? ': ' . $raw : '.');
    }

    private function sendViaCloudApi(string $phone, string $body): string
    {
        $phoneId = $this->settings->getString('whatsapp_cloud_phone_id');
        $token = $this->settings->getString('whatsapp_cloud_token');
        if ($phoneId === '' || $token === '') {
            throw new RuntimeException('The WhatsApp Cloud API phone number ID and access token are both required.');
        }

        $template = $this->settings->getString('whatsapp_cloud_template');
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => ltrim($phone, '+'),
        ];
        if ($template !== '') {
            $language = $this->settings->getString('whatsapp_cloud_language', 'en_US') ?: 'en_US';
            $payload['type'] = 'template';
            $payload['template'] = [
                'name'       => $template,
                'language'   => ['code' => $language],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => [['type' => 'text', 'text' => self::flatten($body)]],
                ]],
            ];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['preview_url' => false, 'body' => mb_substr($body, 0, self::CLOUD_MAX_CHARS)];
        }

        try {
            $response = $this->client->post('https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode($phoneId) . '/messages', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'json'    => $payload,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('WhatsApp Cloud API request failed: ' . self::sanitize($e->getMessage(), [$token]));
        }

        $decoded = json_decode((string) $response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300 || isset($decoded['error'])) {
            throw new RuntimeException('WhatsApp Cloud API error: ' . self::sanitize(self::cloudError($decoded, $status), [$token]));
        }
        if (!isset($decoded['messages'][0]['id'])) {
            throw new RuntimeException('WhatsApp Cloud API did not confirm that the message was accepted.');
        }
        return 'WhatsApp ' . $phone;
    }

    /**
     * Meta nests the useful part of a failure under error.error_data.details; error.message on its own is
     * often only "(#131030) Recipient phone number not in allowed list".
     *
     * @param array<string, mixed> $decoded
     */
    private static function cloudError(array $decoded, int $status): string
    {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $parts = [];
        if (isset($error['message']) && is_scalar($error['message'])) {
            $parts[] = (string) $error['message'];
        }
        $details = $error['error_data']['details'] ?? null;
        if (is_scalar($details) && (string) $details !== '') {
            $parts[] = (string) $details;
        }
        if ($parts === []) {
            $parts[] = 'HTTP ' . $status;
        }
        return implode(' - ', $parts);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Template parameters may not contain newlines, tabs or runs of four or more spaces, so a multi-line
     * alert is folded onto one line before it is handed to a template. Nothing else is removed: WhatsApp's
     * *bold* and _italic_ markup is valid inside a parameter, and stripping those characters would quietly
     * rewrite any URL that contains an underscore.
     */
    public static function flatten(string $body, int $limit = 900): string
    {
        $line = preg_replace('/\s*\R+\s*/u', ' | ', trim($body)) ?? $body;
        $line = preg_replace('/(?:\s*\|\s*)+/u', ' | ', $line) ?? $line;
        $line = preg_replace('/[^\S\r\n]{2,}/u', ' ', $line) ?? $line;
        return mb_substr(trim($line, " |\t"), 0, $limit);
    }

    /** Turn an HTML or text response body into a short single-line message. */
    private static function plain(string $body): string
    {
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Keep API keys and access tokens out of the delivery log and the error surfaced in the interface.
     *
     * @param array<int, string> $secrets
     */
    private static function sanitize(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        return mb_substr(self::plain($text), 0, 400);
    }
}
