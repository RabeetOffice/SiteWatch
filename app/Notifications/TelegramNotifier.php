<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Repositories\SettingsRepository;
use GuzzleHttp\Client;
use RuntimeException;
use Throwable;

/**
 * Telegram Bot API channel (sendMessage with HTML formatting).
 */
final class TelegramNotifier implements NotifierInterface
{
    private Client $client;

    public function __construct(private readonly SettingsRepository $settings, ?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'base_uri'        => 'https://api.telegram.org/',
            'timeout'         => 12,
            'connect_timeout' => 8,
            'http_errors'     => false,
        ]);
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBool('telegram_enabled')
            && $this->settings->getString('telegram_bot_token') !== ''
            && $this->settings->getString('telegram_chat_id') !== '';
    }

    public function send(AlertMessage $message): string
    {
        $token = $this->settings->getString('telegram_bot_token');
        $chatId = $this->settings->getString('telegram_chat_id');
        if ($token === '' || $chatId === '') {
            throw new RuntimeException('Telegram bot token or chat ID is not configured.');
        }
        if (!preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new RuntimeException('The Telegram bot token format looks invalid.');
        }

        try {
            $response = $this->client->post('bot' . $token . '/sendMessage', [
                'json' => [
                    'chat_id'                  => $chatId,
                    'text'                     => mb_substr($message->telegram, 0, 4000),
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram request failed: ' . self::sanitize($e->getMessage(), $token));
        }

        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($body) || empty($body['ok'])) {
            $description = is_array($body) && isset($body['description']) ? (string) $body['description'] : 'HTTP ' . $response->getStatusCode();
            throw new RuntimeException('Telegram API error: ' . self::sanitize($description, $token));
        }
        return 'chat ' . $chatId;
    }

    /**
     * Ensure the bot token never ends up in logs.
     */
    private static function sanitize(string $text, string $token): string
    {
        return mb_substr(str_replace($token, '[token]', $text), 0, 400);
    }
}
