<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Monitoring\ProbeResult;
use App\Monitoring\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SsrfGuardTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function addresses(): array
    {
        return [
            'public ipv4'              => ['93.184.216.34', true],
            'public ipv4 (8.8.8.8)'    => ['8.8.8.8', true],
            'loopback'                 => ['127.0.0.1', false],
            'loopback range'           => ['127.255.255.254', false],
            'private 10/8'             => ['10.20.30.40', false],
            'private 172.16/12'        => ['172.31.255.1', false],
            'not private 172.32'       => ['172.32.0.1', true],
            'private 192.168/16'       => ['192.168.0.1', false],
            'link-local / metadata'    => ['169.254.169.254', false],
            'cgnat'                    => ['100.64.1.1', false],
            'this network'             => ['0.0.0.0', false],
            'multicast'                => ['224.0.0.1', false],
            'broadcast'                => ['255.255.255.255', false],
            'test-net'                 => ['192.0.2.1', false],
            'ipv6 loopback'            => ['::1', false],
            'ipv6 unspecified'         => ['::', false],
            'ipv6 unique local'        => ['fd00:ec2::254', false],
            'ipv6 link-local'          => ['fe80::1', false],
            'ipv6 multicast'           => ['ff02::1', false],
            'ipv6 documentation'       => ['2001:db8::1', false],
            'ipv6 public'              => ['2606:2800:220:1:248:1893:25c8:1946', true],
            'ipv4-mapped private'      => ['::ffff:192.168.1.1', false],
            'ipv4-mapped public'       => ['::ffff:8.8.8.8', true],
            'nat64 private'            => ['64:ff9b::7f00:1', false],
            '6to4 private'             => ['2002:c0a8:0101::1', false],
            'garbage'                  => ['not-an-ip', false],
        ];
    }

    #[DataProvider('addresses')]
    public function testIsPublicIp(string $ip, bool $expected): void
    {
        self::assertSame($expected, SsrfGuard::isPublicIp($ip));
    }

    public function testBlockedHostnamesAreRejected(): void
    {
        $guard = new SsrfGuard(false);
        foreach (['http://localhost/', 'http://metadata.google.internal/', 'http://foo.localhost/', 'http://server.internal/'] as $url) {
            $result = $guard->resolve($url);
            self::assertFalse($result['ok'], $url);
            self::assertSame(ProbeResult::ERROR_BLOCKED, $result['error_kind'], $url);
        }
    }

    public function testPrivateLiteralIpIsRejectedUnlessAllowed(): void
    {
        $strict = new SsrfGuard(false);
        $result = $strict->resolve('http://127.0.0.1:8089/ok');
        self::assertFalse($result['ok']);
        self::assertSame(ProbeResult::ERROR_BLOCKED, $result['error_kind']);
        self::assertSame(8089, $result['port']);

        $trusted = new SsrfGuard(true);
        $result = $trusted->resolve('http://127.0.0.1:8089/ok');
        self::assertTrue($result['ok']);
        self::assertSame(['127.0.0.1'], $result['ips']);
    }

    public function testPublicLiteralIpIsAccepted(): void
    {
        $result = (new SsrfGuard(false))->resolve('https://93.184.216.34/');
        self::assertTrue($result['ok']);
        self::assertSame(443, $result['port']);
        self::assertSame(['93.184.216.34'], $result['ips']);
    }

    public function testInvalidUrl(): void
    {
        $result = (new SsrfGuard(false))->resolve('ftp://example.com/');
        self::assertFalse($result['ok']);
        self::assertSame(ProbeResult::ERROR_INVALID_URL, $result['error_kind']);
    }

    public function testUnresolvableHostIsDnsError(): void
    {
        $result = (new SsrfGuard(false))->resolve('http://definitely-not-a-real-host.invalid/');
        self::assertFalse($result['ok']);
        self::assertSame(ProbeResult::ERROR_DNS, $result['error_kind']);
    }
}
