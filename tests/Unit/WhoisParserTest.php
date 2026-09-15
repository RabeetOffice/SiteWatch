<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\WhoisParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Synthetic records in the layouts real registries use.
 */
final class WhoisParserTest extends TestCase
{
    private const ICANN = <<<'TXT'
Domain Name: EXAMPLE-AUTHOR.COM
Registry Domain ID: 123456_DOMAIN_COM-VRSN
Registrar WHOIS Server: whois.registrar.example
Registrar URL: http://www.registrar.example
Updated Date: 2026-08-06T08:45:20Z
Creation Date: 2019-08-06T08:42:58Z
Registry Expiry Date: 2027-08-06T08:42:58Z
Registrar: Example Registrar, Inc.
Registrar IANA ID: 1068
Registrar Abuse Contact Email: abuse@registrar.example
Domain Status: clientTransferProhibited https://icann.org/epp#clientTransferProhibited
Domain Status: clientDeleteProhibited https://icann.org/epp#clientDeleteProhibited
Registrant Name: REDACTED FOR PRIVACY
Registrant Organization: Example Books Ltd
Registrant Country: GB
Name Server: NS1.HOST.EXAMPLE
Name Server: ns2.host.example.
DNSSEC: signedDelegation
>>> Last update of whois database: 2026-09-15T07:08:39Z <<<

For more information on Whois status codes, please visit https://icann.org/epp
TXT;

    private const NOMINET = <<<'TXT'

    Domain name:
        example-publishing.co.uk

    Registrar:
        Example Registrar Ltd [Tag = EXAMPLE-TAG]
        URL: https://www.registrar.example

    Relevant dates:
        Registered on: 09-Apr-2025
        Expiry date:  09-Apr-2027
        Last updated:  20-Mar-2026

    Registration status:
        Registered until expiry date.

    Name servers:
        dns1.host.example
        dns2.host.example

    WHOIS lookup made at 08:09:02 15-Sep-2026
TXT;

    private const EURID = <<<'TXT'
% The WHOIS service offered by EURid
Domain: example.eu
Script: LATIN

Registrant:
        NOT DISCLOSED!
        Visit www.eurid.eu for the web-based WHOIS.

Technical:
        Organisation: Tech Contact NV
        Email: support@tech.example

Registrar:
        Name: Example Registrar NV
        Website: https://www.registrar.example

Name servers:
        ns1.example.eu (192.0.2.10)
        ns2.host.example
TXT;

    private const DENIC = <<<'TXT'
% Restricted rights.

Domain: example.de
Nserver: ns1.example.de
Nserver: ns2.example.de
Status: connect
Changed: 2018-03-12T21:44:25+01:00
TXT;

    public function testIcannStyleRecord(): void
    {
        $r = WhoisParser::parse(self::ICANN);

        self::assertTrue($r['found']);
        self::assertSame('Example Registrar, Inc.', $r['registrar']);
        self::assertSame('http://www.registrar.example', $r['registrar_url']);
        self::assertSame('whois.registrar.example', $r['registrar_whois']);
        self::assertSame('abuse@registrar.example', $r['abuse_email']);
        self::assertSame('2019-08-06 08:42:58', $r['registered_at']);
        self::assertSame('2027-08-06 08:42:58', $r['expires_at']);
        self::assertSame('2026-08-06 08:45:20', $r['updated_at']);
        self::assertSame(['ns1.host.example', 'ns2.host.example'], $r['nameservers']);
        self::assertSame(['clientTransferProhibited', 'clientDeleteProhibited'], $r['statuses']);
        self::assertSame('Example Books Ltd', $r['registrant'], 'redacted names are skipped in favour of the organisation');
        self::assertSame('GB', $r['registrant_country']);
        self::assertTrue($r['dnssec']);
    }

    public function testNominetSectionRecord(): void
    {
        $r = WhoisParser::parse(self::NOMINET);

        self::assertTrue($r['found']);
        self::assertSame('Example Registrar Ltd', $r['registrar'], 'the [Tag = …] suffix is removed');
        self::assertSame('https://www.registrar.example', $r['registrar_url']);
        self::assertSame('2025-04-09 00:00:00', $r['registered_at']);
        self::assertSame('2027-04-09 00:00:00', $r['expires_at']);
        self::assertSame('2026-03-20 00:00:00', $r['updated_at']);
        self::assertSame(['dns1.host.example', 'dns2.host.example'], $r['nameservers']);
        self::assertSame(['Registered until expiry date.'], $r['statuses']);
    }

    public function testEuridSectionRecord(): void
    {
        $r = WhoisParser::parse(self::EURID);

        self::assertTrue($r['found']);
        self::assertSame('Example Registrar NV', $r['registrar']);
        self::assertSame('https://www.registrar.example', $r['registrar_url']);
        self::assertSame(['ns1.example.eu', 'ns2.host.example'], $r['nameservers'], 'glue addresses are stripped');
        self::assertNull($r['registrant'], 'NOT DISCLOSED is treated as redacted');
        self::assertNull($r['registered_at']);
    }

    public function testTerseRegistryRecord(): void
    {
        $r = WhoisParser::parse(self::DENIC);

        self::assertTrue($r['found']);
        self::assertSame(['ns1.example.de', 'ns2.example.de'], $r['nameservers']);
        self::assertSame('2018-03-12 20:44:25', $r['updated_at'], 'offsets are converted to UTC');
    }

    public function testNotFound(): void
    {
        self::assertFalse(WhoisParser::parse("Not found: missing-domain.ie\n\n% Important Notice\n")['found']);
        self::assertFalse(WhoisParser::parse("No match for \"MISSING-DOMAIN.COM\".\n>>> Last update of whois database <<<\n")['found']);
        self::assertFalse(WhoisParser::parse("Domain: missing.de\nStatus: free\n")['found']);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function dates(): array
    {
        return [
            'ISO Zulu'             => ['2026-08-06T08:42:58Z', '2026-08-06 08:42:58'],
            'ISO with microsecond' => ['2025-04-09T15:50:20.495292Z', '2025-04-09 15:50:20'],
            'ISO with offset'      => ['2018-03-12T21:44:25+01:00', '2018-03-12 20:44:25'],
            'plain date'           => ['2024-02-29', '2024-02-29 00:00:00'],
            'day-month name-year'  => ['09-Apr-2025', '2025-04-09 00:00:00'],
            'dotted day first'     => ['05.01.2020', '2020-01-05 00:00:00'],
            'dotted year first'    => ['2020.01.05', '2020-01-05 00:00:00'],
            'with UTC suffix'      => ['2021-06-01 10:00:00 UTC', '2021-06-01 10:00:00'],
            'garbage'              => ['not a date', null],
            'implausible year'     => ['1970-01-01', null],
        ];
    }

    #[DataProvider('dates')]
    public function testDates(string $input, ?string $expected): void
    {
        self::assertSame($expected, WhoisParser::date($input));
    }
}
