<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\DomainName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainNameTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function hosts(): array
    {
        return [
            'bare .com'             => ['example.com', 'example.com'],
            'www .com'              => ['www.example.com', 'example.com'],
            'deep subdomain'        => ['blog.shop.example.com', 'example.com'],
            'co.uk'                 => ['www.example.co.uk', 'example.co.uk'],
            'bare co.uk'            => ['example.co.uk', 'example.co.uk'],
            'com.au'                => ['shop.example.com.au', 'example.com.au'],
            'single-level ccTLD'    => ['www.example.ie', 'example.ie'],
            'case and trailing dot' => ['WWW.Example.COM.', 'example.com'],
            'unknown second level'  => ['www.example.gov.zz', 'gov.zz'],
        ];
    }

    #[DataProvider('hosts')]
    public function testRegistrable(string $host, string $expected): void
    {
        self::assertSame($expected, DomainName::registrable($host));
    }

    public function testCandidatesTryTheLongerNameForUnknownSuffixes(): void
    {
        self::assertSame(['gov.zz', 'example.gov.zz'], DomainName::candidates('www.example.gov.zz'));
    }

    public function testCandidatesNeverQueryWwwNames(): void
    {
        self::assertSame(['example.com'], DomainName::candidates('www.example.com'));
        self::assertSame(['example.co.uk'], DomainName::candidates('www.example.co.uk'));
        self::assertSame(['example.com', 'shop.example.com'], DomainName::candidates('shop.example.com'));
    }

    public function testFromInputAcceptsDomainsAndUrls(): void
    {
        self::assertSame('www.example.co.uk', DomainName::fromInput('https://www.Example.co.uk/path?x=1'));
        self::assertSame('example.com', DomainName::fromInput('  example.com '));
    }

    public function testFromInputRejectsNonDomains(): void
    {
        self::assertNull(DomainName::fromInput(''));
        self::assertNull(DomainName::fromInput('not a domain'));
        self::assertNull(DomainName::fromInput('127.0.0.1'));
        self::assertNull(DomainName::fromInput('localhost'));
        self::assertNull(DomainName::fromInput('ftp://example.com'));
    }

    public function testTld(): void
    {
        self::assertSame('uk', DomainName::tld('example.co.uk'));
        self::assertSame('com', DomainName::tld('Example.COM.'));
    }
}
