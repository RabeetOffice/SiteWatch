<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Countries\CheckHostClient;
use App\Countries\Countries;
use App\Countries\CountryClassifier;
use App\Countries\GlobalpingClient;
use App\Countries\JsonHttp;
use App\Countries\Probe;
use PHPUnit\Framework\TestCase;

/**
 * Country availability: parsing what the probe providers return and deciding what a failure in one country means.
 */
final class CountryCheckTest extends TestCase
{
    private const SLOW = 5000;

    private static function ok(string $country, int $ms = 800): Probe
    {
        return new Probe($country, 'globalping', true, 200, $ms, '104.20.23.154');
    }

    private static function failed(string $country, string $error): Probe
    {
        return new Probe($country, 'globalping', false, null, null, null, $error);
    }

    private static function answer(string $country, int $code, string $body = ''): Probe
    {
        return new Probe($country, 'globalping', true, $code, 300, '104.20.23.154', null, null, null, null, null, [], $body);
    }

    /** @return array<string, Probe> */
    private static function allOk(array $countries): array
    {
        $out = [];
        foreach ($countries as $c) {
            $out[$c] = self::ok($c);
        }
        return $out;
    }

    public function testParseKeepsKnownCodesWithoutDuplicatesAndCapsTheList(): void
    {
        self::assertSame(['US', 'GB', 'PK'], Countries::parse('us, gb;PK,XX,US'));
        self::assertCount(Countries::MAX, Countries::parse(implode(',', array_keys(Countries::ALL))));
        self::assertSame(Countries::parse(Countries::DEFAULT), Countries::parse(''));
        self::assertCount(15, Countries::parse(Countries::DEFAULT));
    }

    public function testReachableAndSlow(): void
    {
        $c = new CountryClassifier();
        self::assertSame(CountryClassifier::REACHABLE, $c->single(self::ok('US', 900), self::SLOW));
        self::assertSame(CountryClassifier::SLOW, $c->single(self::ok('US', 6200), self::SLOW));
        self::assertSame(CountryClassifier::REACHABLE, $c->single(self::answer('US', 301), self::SLOW), 'A redirect is a normal answer.');
    }

    public function testSignsOfBlocking(): void
    {
        $c = new CountryClassifier();
        self::assertSame(CountryClassifier::BLOCKED, $c->single(self::answer('RU', 451), self::SLOW));
        self::assertSame(CountryClassifier::BLOCKED, $c->single(self::failed('TR', 'read ECONNRESET'), self::SLOW));
        self::assertSame(CountryClassifier::BLOCKED, $c->single(self::failed('CN', 'queryA ENOTFOUND example.com'), self::SLOW));
        self::assertSame(CountryClassifier::BLOCKED, $c->single(new Probe('PK', 'globalping', false, null, null, '10.10.34.34', 'connect ETIMEDOUT'), self::SLOW), 'A private DNS answer is a redirected name.');
        self::assertSame(CountryClassifier::BLOCKED, $c->single(self::answer('PK', 200, '<h1>This URL has been blocked</h1> as per PTA directions'), self::SLOW), 'A block page with 200 is still a block page.');
    }

    public function testSiteRefusalsAndPlainFailures(): void
    {
        $c = new CountryClassifier();
        self::assertSame(CountryClassifier::GEO_BLOCKED, $c->single(self::answer('IN', 403), self::SLOW));
        self::assertSame(CountryClassifier::GEO_BLOCKED, $c->single(self::answer('IN', 429), self::SLOW));
        self::assertSame(CountryClassifier::UNREACHABLE, $c->single(self::answer('IN', 502), self::SLOW));
        self::assertSame(CountryClassifier::UNREACHABLE, $c->single(self::failed('ZA', 'connect ETIMEDOUT 1.2.3.4:443'), self::SLOW));
    }

    public function testCountryProblemNeedsASecondNetworkToBeConfirmed(): void
    {
        $countries = ['US', 'GB', 'DE', 'PK', 'TR'];
        $first = self::allOk($countries);
        $first['TR'] = self::failed('TR', 'read ECONNRESET');
        $first['PK'] = self::failed('PK', 'connect ETIMEDOUT');

        // Turkey fails again on another network; Pakistan's second probe opens the site.
        $second = ['TR' => self::failed('TR', 'read ECONNRESET'), 'PK' => self::ok('PK')];
        $r = (new CountryClassifier())->classify($first, $second, $countries, self::SLOW);

        self::assertSame(CountryClassifier::BLOCKED, $r['TR']['result']);
        self::assertTrue($r['TR']['confirmed']);
        self::assertSame(CountryClassifier::REACHABLE, $r['PK']['result'], 'One network with a problem is not a country problem.');
        self::assertFalse($r['PK']['confirmed']);
        self::assertSame(CountryClassifier::REACHABLE, $r['US']['result']);
    }

    public function testMostCountriesFailingIsAnOutageNotACountryProblem(): void
    {
        $countries = ['US', 'GB', 'DE', 'PK', 'TR'];
        $first = [];
        foreach ($countries as $c) {
            $first[$c] = self::failed($c, 'connect ECONNREFUSED');
        }
        $first['US'] = self::ok('US');
        $r = (new CountryClassifier())->classify($first, [], $countries, self::SLOW);
        self::assertSame(CountryClassifier::DOWN, $r['GB']['result']);
        self::assertSame(CountryClassifier::REACHABLE, $r['US']['result']);
    }

    public function testBlockWordsOnTheSitesOwnPageAreIgnored(): void
    {
        $countries = ['US', 'GB', 'DE', 'PK'];
        $first = [];
        foreach ($countries as $c) {
            $first[$c] = self::answer($c, 200, '<p>Why this site is blocked in some offices</p>');
        }
        $r = (new CountryClassifier())->classify($first, [], $countries, self::SLOW);
        self::assertSame(CountryClassifier::REACHABLE, $r['PK']['result']);
    }

    public function testCountriesWithoutAProbeAreNeverReportedAsBlocked(): void
    {
        $r = (new CountryClassifier())->classify(['US' => self::ok('US')], [], ['US', 'CN'], self::SLOW);
        self::assertSame(CountryClassifier::NO_PROBE, $r['CN']['result']);
        self::assertNull($r['CN']['probe']);
    }

    public function testBogonAddresses(): void
    {
        self::assertTrue(CountryClassifier::isBogon('127.0.0.1'));
        self::assertTrue(CountryClassifier::isBogon('0.0.0.0'));
        self::assertTrue(CountryClassifier::isBogon('192.168.1.1'));
        self::assertFalse(CountryClassifier::isBogon('104.20.23.154'));
        self::assertFalse(CountryClassifier::isBogon('not an ip'));
    }

    public function testGlobalpingResultParsing(): void
    {
        $client = new GlobalpingClient(new JsonHttp('test'));
        $probe = $client->parse([
            'probe'  => ['country' => 'DE', 'city' => 'Falkenstein', 'asn' => 24940, 'network' => 'Hetzner Online', 'tags' => ['datacenter-network']],
            'result' => ['status' => 'finished', 'statusCode' => 200, 'resolvedAddress' => '104.20.23.154', 'timings' => ['total' => 27],
                'headers' => ['server' => 'cloudflare', 'CF-RAY' => 'abc'], 'rawBody' => '<html></html>'],
        ]);
        self::assertNotNull($probe);
        self::assertSame('DE', $probe->country);
        self::assertTrue($probe->isOk());
        self::assertSame(27, $probe->responseMs);
        self::assertSame('datacenter', $probe->type);
        self::assertSame('Cloudflare', CountryClassifier::firewallName($probe));

        $failed = $client->parse([
            'probe'  => ['country' => 'GB', 'tags' => ['eyeball-network']],
            'result' => ['status' => 'failed', 'rawOutput' => "queryA ENOTFOUND\n  example.invalid", 'timings' => ['total' => null]],
        ]);
        self::assertNotNull($failed);
        self::assertFalse($failed->answered);
        self::assertSame('home', $failed->type);
        self::assertSame('queryA ENOTFOUND example.invalid', $failed->error);

        self::assertNull($client->parse(['probe' => ['country' => 'Germany'], 'result' => []]));
    }

    public function testCheckHostResultParsing(): void
    {
        $client = new CheckHostClient(new JsonHttp('test'));
        $info = ['node' => 'tr1.node.check-host.net', 'city' => 'Istanbul', 'asn' => 'AS211557'];

        $ok = $client->parse('TR', $info, [1, 0.180495, 'OK', '200', '172.66.147.243']);
        self::assertTrue($ok->isOk());
        self::assertSame(180, $ok->responseMs);
        self::assertSame(211557, $ok->asn);

        $timeout = $client->parse('TR', $info, [0, 30.0, 'Connection timed out', null, null]);
        self::assertFalse($timeout->answered);
        self::assertSame('Connection timed out', $timeout->error);
        self::assertSame(CountryClassifier::UNREACHABLE, (new CountryClassifier())->single($timeout, self::SLOW));
    }
}
