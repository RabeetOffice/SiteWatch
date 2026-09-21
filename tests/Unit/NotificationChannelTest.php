<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\AlertMessage;
use App\Notifications\DiscordNotifier;
use App\Notifications\WhatsAppNotifier;
use PHPUnit\Framework\TestCase;

/**
 * Input handling for the WhatsApp and Discord channels. Delivery itself needs configured credentials and is
 * exercised by the "Send test message" buttons; what is checked here is everything that decides whether a
 * request is made at all, and where to.
 */
final class NotificationChannelTest extends TestCase
{
    // ------------------------------------------------------------------
    // WhatsApp
    // ------------------------------------------------------------------

    public function testNormalizePhoneAcceptsCommonlyPastedFormats(): void
    {
        self::assertSame('+923001234567', WhatsAppNotifier::normalizePhone('+923001234567'));
        self::assertSame('+923001234567', WhatsAppNotifier::normalizePhone('+92 300 123-4567'));
        self::assertSame('+923001234567', WhatsAppNotifier::normalizePhone('  923001234567  '));
        self::assertSame('+14155550123', WhatsAppNotifier::normalizePhone('+1 (415) 555.0123'));
    }

    public function testNormalizePhoneRejectsAnythingNotE164(): void
    {
        // A national number keeps its trunk prefix; a country code never starts with zero.
        self::assertSame('', WhatsAppNotifier::normalizePhone('03001234567'));
        self::assertSame('', WhatsAppNotifier::normalizePhone(''));
        self::assertSame('', WhatsAppNotifier::normalizePhone('not a number'));
        self::assertSame('', WhatsAppNotifier::normalizePhone('+12345'), 'too short');
        self::assertSame('', WhatsAppNotifier::normalizePhone('+1234567890123456'), 'too long');
        self::assertSame('', WhatsAppNotifier::normalizePhone('+92300123456;curl evil'));
    }

    public function testFlattenProducesOneLineForATemplateParameter(): void
    {
        $body = "🚨 *Website Down*\n\n*Acme Corp*\nHTTP 500 Server Error\n\nHTTP: 500\n";

        $flat = WhatsAppNotifier::flatten($body);

        self::assertSame('🚨 *Website Down* | *Acme Corp* | HTTP 500 Server Error | HTTP: 500', $flat);
        // Meta rejects newlines, tabs and runs of four or more spaces in template parameters.
        self::assertDoesNotMatchRegularExpression('/[\r\n\t]|\x20{4}/', $flat);
    }

    public function testFlattenLeavesUrlsIntact(): void
    {
        // Underscores are WhatsApp's italic marker but also legal in a path, so they must survive.
        self::assertSame(
            'Down | https://example.com/a_b~c*d',
            WhatsAppNotifier::flatten("Down\n\nhttps://example.com/a_b~c*d")
        );
    }

    public function testFlattenTruncatesToTheGivenLimit(): void
    {
        self::assertSame(20, mb_strlen(WhatsAppNotifier::flatten(str_repeat('a', 500), 20)));
    }

    public function testGreenChatIdUsesTheBareDigits(): void
    {
        self::assertSame('923001234567@c.us', WhatsAppNotifier::greenChatId('+923001234567'));
        self::assertSame('923001234567@c.us', WhatsAppNotifier::greenChatId('923001234567'));
    }

    public function testGreenApiUrlAcceptsGreenApisOwnHosts(): void
    {
        self::assertSame('https://api.green-api.com', WhatsAppNotifier::greenApiUrl('https://api.green-api.com'));
        self::assertSame('https://7103.api.greenapi.com', WhatsAppNotifier::greenApiUrl('https://7103.api.greenapi.com/'));
        self::assertSame('https://api.greenapi.com', WhatsAppNotifier::greenApiUrl('  https://api.greenapi.com  '));
    }

    public function testGreenApiUrlFallsBackRatherThanCallingAnArbitraryHost(): void
    {
        // The value is administrator-supplied and drives a server-side request carrying the API token.
        $rejected = [
            '',
            'https://evil.test',
            'http://api.green-api.com',              // plain http
            'https://green-api.com.evil.test',       // suffixed host
            'https://evilgreen-api.com',             // look-alike without a real subdomain boundary
            'https://api.green-api.com@evil.test',   // userinfo trick
            'https://api.green-api.com/../../evil',  // path smuggling
            'not a url',
        ];
        foreach ($rejected as $bad) {
            self::assertSame(WhatsAppNotifier::GREEN_DEFAULT_URL, WhatsAppNotifier::greenApiUrl($bad), $bad);
        }
    }

    // ------------------------------------------------------------------
    // Discord
    // ------------------------------------------------------------------

    public function testValidWebhookAcceptsDiscordsOwnHosts(): void
    {
        $token = str_repeat('a1b2C3_-', 8);
        foreach (['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'] as $host) {
            self::assertTrue(DiscordNotifier::isValidWebhook("https://{$host}/api/webhooks/123456789012345678/{$token}"), $host);
        }
        self::assertTrue(DiscordNotifier::isValidWebhook("https://discord.com/api/v10/webhooks/123456789012345678/{$token}"));
    }

    public function testValidWebhookRejectsAnythingElse(): void
    {
        $token = str_repeat('a1b2C3_-', 8);

        self::assertFalse(DiscordNotifier::isValidWebhook(''));
        self::assertFalse(DiscordNotifier::isValidWebhook("http://discord.com/api/webhooks/123456789012345678/{$token}"), 'plain http');
        self::assertFalse(DiscordNotifier::isValidWebhook("https://discord.com.evil.test/api/webhooks/123456789012345678/{$token}"), 'suffixed host');
        self::assertFalse(DiscordNotifier::isValidWebhook("https://evil.test/?u=https://discord.com/api/webhooks/1/{$token}"), 'embedded in a query string');
        self::assertFalse(DiscordNotifier::isValidWebhook('https://discord.com/api/webhooks/123456789012345678/short'), 'truncated token');
        self::assertFalse(DiscordNotifier::isValidWebhook('https://127.0.0.1/api/webhooks/123456789012345678/' . $token), 'internal address');
    }

    public function testEscapeMarkdownNeutralisesTextTakenFromAMonitoredSite(): void
    {
        // Diagnostics quote the monitored page's own response, so a hostile page must not be able to post
        // a clickable link, or restyle the embed, in the team's channel.
        self::assertSame(
            'Fatal: \\[Click here\\](https://evil.test) \\*pwned\\* \\`code\\`',
            DiscordNotifier::escapeMarkdown('Fatal: [Click here](https://evil.test) *pwned* `code`')
        );
        self::assertSame('a \\\\ b', DiscordNotifier::escapeMarkdown('a \\ b'));
    }

    public function testEscapeMarkdownLeavesABareUrlAlone(): void
    {
        // The URL row is a real link; backslashes would be shown literally by Discord.
        self::assertSame('https://example.com/a_b', DiscordNotifier::escapeMarkdown('https://example.com/a_b'));
    }

    public function testDescribeIdentifiesTheWebhookWithoutLeakingItsToken(): void
    {
        $token = str_repeat('a1b2C3_-', 8);
        $url = "https://discord.com/api/webhooks/123456789012345678/{$token}";

        $described = DiscordNotifier::describe($url);

        self::assertSame('Discord webhook 123456789012345678', $described);
        self::assertStringNotContainsString($token, $described);
    }

    // ------------------------------------------------------------------
    // Shared payload
    // ------------------------------------------------------------------

    public function testWhatsappBodyFallsBackToPlainText(): void
    {
        $withoutWhatsapp = new AlertMessage(AlertMessage::EVENT_TEST, 'Subject', 'Plain text body', '<p>html</p>', 'telegram');
        $withWhatsapp = new AlertMessage(AlertMessage::EVENT_TEST, 'Subject', 'Plain text body', '<p>html</p>', 'telegram', null, null, '*WhatsApp body*');

        self::assertSame('Plain text body', $withoutWhatsapp->whatsappBody());
        self::assertSame('*WhatsApp body*', $withWhatsapp->whatsappBody());
    }
}
