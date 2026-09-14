<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function urls(): array
    {
        return [
            'bare domain gets https'        => ['example.com', 'https://example.com/'],
            'uppercase scheme + host'       => ['HTTP://Example.COM/Path/', 'http://example.com/Path/'],
            'default https port stripped'   => ['https://example.com:443/', 'https://example.com/'],
            'default http port stripped'    => ['http://example.com:80', 'http://example.com/'],
            'custom port kept'              => ['https://example.com:8443/x', 'https://example.com:8443/x'],
            'fragment removed'              => ['https://example.com/page#top', 'https://example.com/page'],
            'query kept'                    => ['https://example.com/?p=1', 'https://example.com/?p=1'],
            'whitespace trimmed'            => ['  example.com  ', 'https://example.com/'],
            'www kept'                      => ['www.example.co.uk', 'https://www.example.co.uk/'],
            'ipv4 literal'                  => ['http://93.184.216.34/', 'http://93.184.216.34/'],
            'trailing dot removed'          => ['https://example.com./', 'https://example.com/'],
            'ftp rejected'                  => ['ftp://example.com/', null],
            'javascript rejected'           => ['javascript:alert(1)', null],
            'credentials rejected'          => ['https://user:pass@example.com/', null],
            'empty rejected'                => ['', null],
            'no tld rejected'               => ['intranet', null],
            'space inside rejected'         => ['https://exa mple.com/', null],
            'invalid label rejected'        => ['https://-bad-.com/', null],
            'port out of range'             => ['https://example.com:70000/', null],
        ];
    }

    #[DataProvider('urls')]
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, UrlNormalizer::normalize($input));
    }

    public function testIdnHostIsConvertedToPunycode(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('intl extension not available');
        }
        self::assertSame('https://xn--mnchen-3ya.de/', UrlNormalizer::normalize('münchen.de'));
    }

    public function testDomainAndScheme(): void
    {
        self::assertSame('example.com', UrlNormalizer::domain('https://example.com/path'));
        self::assertTrue(UrlNormalizer::isHttps('https://example.com/'));
        self::assertFalse(UrlNormalizer::isHttps('http://example.com/'));
    }
}
