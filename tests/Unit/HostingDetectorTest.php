<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\HostingDetector;
use PHPUnit\Framework\TestCase;

final class HostingDetectorTest extends TestCase
{
    public function testProvidersFromReverseDnsAndNetworkNames(): void
    {
        self::assertSame('Namecheap', HostingDetector::provider('business189-3.web-hosting.com'));
        self::assertSame('Hostinger', HostingDetector::provider('', 'HOSTINGER-AS - Hostinger International Limited, CY'));
        self::assertSame('Hostinger', HostingDetector::provider('srv123.hstgr.cloud'));
        self::assertSame('WP Engine', HostingDetector::provider('mysite.wpengine.com'));
        self::assertSame('Amazon Web Services', HostingDetector::provider('ec2-3-8-1-1.eu-west-2.compute.amazonaws.com'));
        self::assertSame('Linode (Akamai Cloud)', HostingDetector::provider('Akamai Connected Cloud, SG'));
        self::assertNull(HostingDetector::provider('mail.unknown-company.example'));
    }

    public function testSignaturesMatchWholeLabelsOnly(): void
    {
        self::assertNull(HostingDetector::provider('flaws.example'), '"aws" must not match inside another word');
        self::assertNull(HostingDetector::provider('srv20i.example'), '"20i" must not match inside another label');
    }

    public function testCdnDetection(): void
    {
        self::assertSame('Cloudflare', HostingDetector::cdn('CLOUDFLARENET - Cloudflare, Inc., US'));
        self::assertSame('Fastly', HostingDetector::cdn('FASTLY - Fastly, Inc., US'));
        self::assertSame('Amazon CloudFront', HostingDetector::cdn('', 'd111111abcdef8.cloudfront.net'));
        self::assertNull(HostingDetector::cdn('AKAMAI-LINODE-AP - Akamai Connected Cloud, SG'), 'Linode servers are hosting, not the Akamai CDN');
        self::assertNull(HostingDetector::cdn('NAMECHEAP-NET - Namecheap, Inc., US'));
    }

    public function testDnsAndEmailProviders(): void
    {
        self::assertSame('Cloudflare', HostingDetector::dnsProvider(['blue.foundationdns.com']));
        self::assertSame('Amazon Route 53', HostingDetector::dnsProvider(['ns-692.awsdns-22.net']));
        self::assertSame('Namecheap', HostingDetector::dnsProvider(['dns1.namecheaphosting.com']));
        self::assertSame('Google Workspace', HostingDetector::emailProvider(['aspmx.l.google.com']));
        self::assertSame('Microsoft 365', HostingDetector::emailProvider(['example-com.mail.protection.outlook.com']));
        self::assertSame('Microsoft 365', HostingDetector::emailProvider(['example-ie.u-v1.mx.microsoft']));
        self::assertNull(HostingDetector::emailProvider([]));
    }

    public function testOrganisationName(): void
    {
        self::assertSame('Hostinger International Limited', HostingDetector::organisation('HOSTINGER-AS - Hostinger International Limited, CY'));
        self::assertSame('CLOUDFLARENET', HostingDetector::organisation('CLOUDFLARENET, US'));
        self::assertNull(HostingDetector::organisation(''));
    }

    public function testResponseHeaders(): void
    {
        $cloudflare = HostingDetector::fromHeaders(['server' => 'cloudflare', 'cf-ray' => '8c1d2e3f4a5b-LHR']);
        self::assertSame('Cloudflare', $cloudflare['cdn']);
        self::assertNull($cloudflare['server'], 'a CDN name is not web server software');

        $hostinger = HostingDetector::fromHeaders(['server' => 'LiteSpeed', 'platform' => 'hostinger', 'x-powered-by' => 'PHP/8.2.12']);
        self::assertSame('Hostinger', $hostinger['platform']);
        self::assertSame('LiteSpeed', $hostinger['server']);
        self::assertSame('PHP/8.2.12', $hostinger['powered_by']);

        self::assertSame('Kinsta', HostingDetector::fromHeaders(['x-kinsta-cache' => 'HIT'])['platform']);
        self::assertSame('WP Engine', HostingDetector::fromHeaders(['wpe-backend' => 'apache'])['platform']);
        self::assertSame('SiteGround', HostingDetector::fromHeaders(['host-header' => '6b7412fb82ca5edfd0917e3957f05d89'])['platform']);
        self::assertSame(['cdn' => null, 'platform' => null, 'server' => 'nginx', 'powered_by' => null], HostingDetector::fromHeaders(['server' => 'nginx']));
    }
}
