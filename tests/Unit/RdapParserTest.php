<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\RdapParser;
use PHPUnit\Framework\TestCase;

final class RdapParserTest extends TestCase
{
    public function testGtldRegistryResponse(): void
    {
        $r = RdapParser::parse([
            'objectClassName' => 'domain',
            'ldhName'         => 'EXAMPLE-AUTHOR.COM',
            'status'          => ['client transfer prohibited'],
            'events'          => [
                ['eventAction' => 'registration', 'eventDate' => '2019-08-06T08:42:58Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2027-08-06T08:42:58Z'],
                ['eventAction' => 'last changed', 'eventDate' => '2026-08-06T08:45:20Z'],
                ['eventAction' => 'last update of RDAP database', 'eventDate' => '2026-09-15T07:08:39Z'],
            ],
            'entities' => [[
                'objectClassName' => 'entity',
                'roles'           => ['registrar'],
                'publicIds'       => [['type' => 'IANA Registrar ID', 'identifier' => '1068']],
                'links'           => [['rel' => 'about', 'href' => 'http://www.registrar.example']],
                'vcardArray'      => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Example Registrar, Inc.']]],
                'entities'        => [[
                    'roles'      => ['abuse'],
                    'vcardArray' => ['vcard', [['fn', [], 'text', ''], ['email', [], 'text', 'abuse@registrar.example']]],
                ]],
            ]],
            'secureDNS'   => ['delegationSigned' => false],
            'nameservers' => [['ldhName' => 'DNS1.HOST.EXAMPLE'], ['ldhName' => 'dns2.host.example.']],
        ]);

        self::assertTrue($r['found']);
        self::assertSame('Example Registrar, Inc.', $r['registrar']);
        self::assertSame('http://www.registrar.example', $r['registrar_url']);
        self::assertSame('1068', $r['registrar_iana_id']);
        self::assertSame('abuse@registrar.example', $r['abuse_email']);
        self::assertSame('2019-08-06 08:42:58', $r['registered_at']);
        self::assertSame('2027-08-06 08:42:58', $r['expires_at']);
        self::assertSame('2026-08-06 08:45:20', $r['updated_at']);
        self::assertSame(['dns1.host.example', 'dns2.host.example'], $r['nameservers']);
        self::assertSame(['client transfer prohibited'], $r['statuses']);
        self::assertFalse($r['dnssec']);
        self::assertNull($r['registrant']);
    }

    public function testRegistrantDetailsAndRedaction(): void
    {
        $registrant = static fn (string $name, array $adrParams, array $adrValue): array => [
            'roles'      => ['registrant'],
            'vcardArray' => ['vcard', [['fn', [], 'text', $name], ['adr', $adrParams, 'text', $adrValue]]],
        ];

        $visible = RdapParser::parse(['objectClassName' => 'domain', 'entities' => [$registrant('Example Books Ltd', ['cc' => 'ie'], ['', '', '', '', '', '', ''])]]);
        self::assertSame('Example Books Ltd', $visible['registrant']);
        self::assertSame('IE', $visible['registrant_country']);

        $fromLabel = RdapParser::parse(['objectClassName' => 'domain', 'entities' => [$registrant('Example Books Ltd', [], ['', '', '', '', '', '', 'Ireland'])]]);
        self::assertSame('Ireland', $fromLabel['registrant_country']);

        $redacted = RdapParser::parse(['objectClassName' => 'domain', 'entities' => [$registrant('REDACTED FOR PRIVACY', [], ['', '', '', '', '', '', ''])]]);
        self::assertNull($redacted['registrant']);
        self::assertNull($redacted['registrant_country']);
    }

    public function testMalformedInputIsTolerated(): void
    {
        $r = RdapParser::parse(['entities' => ['not-an-array', ['roles' => 'registrar', 'vcardArray' => 'broken']], 'events' => [null], 'nameservers' => 'x']);

        self::assertFalse($r['found']);
        self::assertNull($r['registrar']);
        self::assertSame([], $r['nameservers']);
        self::assertNull($r['registered_at']);
    }
}
