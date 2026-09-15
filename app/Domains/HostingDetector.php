<?php

declare(strict_types=1);

namespace App\Domains;

/**
 * Recognises hosting companies, CDNs, DNS and email providers from public signals: reverse DNS names,
 * CNAME targets, network (AS) names, name servers, MX hosts and HTTP response headers.
 */
final class HostingDetector
{
    /** Hosting companies and platforms, most specific first. */
    private const PROVIDERS = [
        'WP Engine'             => ['wpengine', 'wpenginepowered'],
        'Kinsta'                => ['kinsta'],
        'Flywheel'              => ['getflywheel', 'flywheelsites', 'flywheelstaging'],
        'Pantheon'              => ['pantheon'],
        'Pressable'             => ['pressable'],
        'WordPress.com'         => ['automattic', 'wordpress.com', 'wpvip', 'wpcomstaging'],
        'Cloudways'             => ['cloudways'],
        'SiteGround'            => ['siteground', 'sgvps', 'sgcpanel', 'sgsrv'],
        'Hostinger'             => ['hostinger', 'hstgr', 'dns-parking.com'],
        'Namecheap'             => ['namecheap', 'web-hosting.com', 'registrar-servers.com'],
        'GoDaddy'               => ['godaddy', 'secureserver.net', 'domaincontrol.com'],
        'Bluehost'              => ['bluehost'],
        'HostGator'             => ['hostgator', 'websitewelcome.com'],
        'Newfold Digital'       => ['newfold', 'unifiedlayer', 'endurance international'],
        'DreamHost'             => ['dreamhost'],
        'A2 Hosting'            => ['a2hosting', 'supercp.com'],
        'InMotion Hosting'      => ['inmotionhosting', 'servconfig.com'],
        'Liquid Web'            => ['liquidweb', 'nexcess'],
        'IONOS'                 => ['ionos', '1and1', '1und1', 'ui-dns', 'kundenserver'],
        'OVHcloud'              => ['ovh'],
        'Hetzner'               => ['hetzner', 'your-server.de'],
        'DigitalOcean'          => ['digitalocean'],
        'Linode (Akamai Cloud)' => ['linode', 'akamai connected cloud'],
        'Vultr'                 => ['vultr', 'choopa'],
        'Amazon Web Services'   => ['amazonaws', 'amazon', 'aws'],
        'Google Cloud'          => ['googleusercontent', 'google'],
        'Microsoft Azure'       => ['azure', 'cloudapp.net', 'microsoft'],
        'Oracle Cloud'          => ['oracle'],
        'Squarespace'           => ['squarespace'],
        'Wix'                   => ['wix'],
        'Shopify'               => ['shopify'],
        'Webflow'               => ['webflow'],
        'Netlify'               => ['netlify'],
        'Vercel'                => ['vercel'],
        'GitHub Pages'          => ['github'],
        'Krystal'               => ['krystal'],
        '20i'                   => ['20i', 'stackcp', 'stackdns'],
        'Fasthosts'             => ['fasthosts'],
        'Heart Internet'        => ['heartinternet'],
        'UKFast (ANS)'          => ['ukfast'],
        'Blacknight'            => ['blacknight'],
        'Letshost'              => ['letshost'],
        'VentraIP'              => ['ventraip'],
        'Crazy Domains'         => ['crazydomains'],
        'HostPapa'              => ['hostpapa'],
        'Hostwinds'             => ['hostwinds'],
        'Contabo'               => ['contabo'],
        'Rackspace'             => ['rackspace'],
        'Leaseweb'              => ['leaseweb'],
        'Scaleway'              => ['scaleway'],
        'Alibaba Cloud'         => ['alibaba', 'aliyun'],
    ];

    /** Reverse proxies / CDNs that hide the origin server. */
    private const CDNS = [
        'Cloudflare'        => ['cloudflare'],
        'Fastly'            => ['fastly'],
        'Akamai'            => ['akamai'],
        'Amazon CloudFront' => ['cloudfront'],
        'Sucuri'            => ['sucuri'],
        'Imperva'           => ['incapsula', 'imperva'],
        'StackPath'         => ['stackpath', 'highwinds'],
        'BunnyCDN'          => ['bunnycdn', 'b-cdn.net'],
        'CDN77'             => ['cdn77', 'datacamp'],
        'QUIC.cloud'        => ['quic.cloud'],
        'Edgio'             => ['edgecast', 'edgio', 'limelight'],
        'Azure Front Door'  => ['azurefd.net', 't-msedge.net'],
    ];

    /** Dedicated DNS services (checked before hosting companies for name servers). */
    private const DNS_SERVICES = [
        'Cloudflare'       => ['cloudflare', 'foundationdns'],
        'Amazon Route 53'  => ['awsdns'],
        'Google Cloud DNS' => ['googledomains'],
        'Azure DNS'        => ['azure-dns'],
        'NS1'              => ['nsone'],
        'DNSimple'         => ['dnsimple'],
        'DNS Made Easy'    => ['dnsmadeeasy'],
        'Akamai Edge DNS'  => ['akam.net'],
        'UltraDNS'         => ['ultradns'],
    ];

    private const EMAIL_PROVIDERS = [
        'Google Workspace'        => ['google.com', 'googlemail.com'],
        'Microsoft 365'           => ['outlook.com', 'mx.microsoft'],
        'Zoho Mail'               => ['zoho'],
        'Proton Mail'             => ['protonmail'],
        'Fastmail'                => ['messagingengine.com'],
        'Mimecast'                => ['mimecast'],
        'Proofpoint'              => ['pphosted.com'],
        'iCloud Mail'             => ['icloud.com'],
        'Titan Email'             => ['titan.email'],
        'Hostinger Email'         => ['hostinger'],
        'Namecheap Email'         => ['privateemail.com', 'jellyfish.systems'],
        'GoDaddy Email'           => ['secureserver.net'],
        'Rackspace Email'         => ['emailsrvr.com'],
        'Amazon WorkMail'         => ['awsapps.com'],
        'IONOS Mail'              => ['ionos', 'kundenserver'],
        'OVHcloud Mail'           => ['ovh.net'],
        'Yahoo Mail'              => ['yahoodns.net'],
    ];

    public static function provider(string ...$texts): ?string
    {
        return self::match(self::PROVIDERS, $texts);
    }

    public static function cdn(string ...$texts): ?string
    {
        // Linode's network is announced as "Akamai Connected Cloud" but is ordinary hosting, not the CDN.
        $texts = array_filter($texts, static fn (string $t): bool => !preg_match('/linode|connected cloud/i', $t));
        return self::match(self::CDNS, $texts);
    }

    /** @param array<int, string> $nameservers */
    public static function dnsProvider(array $nameservers): ?string
    {
        return self::match(self::DNS_SERVICES, $nameservers) ?? self::match(self::PROVIDERS, $nameservers);
    }

    /** @param array<int, string> $mxHosts */
    public static function emailProvider(array $mxHosts): ?string
    {
        return self::match(self::EMAIL_PROVIDERS, $mxHosts);
    }

    /**
     * Organisation part of a network name: "HOSTINGER-AS - Hostinger International Limited, CY" -> "Hostinger International Limited".
     */
    public static function organisation(?string $asName): ?string
    {
        $asName = trim((string) $asName);
        if ($asName === '') {
            return null;
        }
        $name = str_contains($asName, ' - ') ? trim(substr($asName, strpos($asName, ' - ') + 3)) : $asName;
        $name = trim((string) preg_replace('/,\s*[A-Z]{2}$/', '', $name));
        return $name !== '' ? $name : $asName;
    }

    /**
     * CDN, hosting platform, server software and "powered by" hints from response headers.
     *
     * @param array<string, string> $headers Lower-case header names.
     * @return array{cdn: ?string, platform: ?string, server: ?string, powered_by: ?string}
     */
    public static function fromHeaders(array $headers): array
    {
        $value = static fn (string $name): string => strtolower((string) ($headers[$name] ?? ''));
        $has = static fn (string $name): bool => array_key_exists($name, $headers);
        $hasPrefix = static function (string $prefix) use ($headers): bool {
            foreach (array_keys($headers) as $key) {
                if (str_starts_with((string) $key, $prefix)) {
                    return true;
                }
            }
            return false;
        };
        $server = $value('server');

        $cdn = match (true) {
            $has('cf-ray') || $server === 'cloudflare'                                          => 'Cloudflare',
            $has('x-amz-cf-id') || str_contains($value('via'), 'cloudfront')                   => 'Amazon CloudFront',
            $has('x-sucuri-id') || str_contains($server, 'sucuri')                             => 'Sucuri',
            $has('x-iinfo') || (bool) preg_match('/imperva|incapsula/', $value('x-cdn'))       => 'Imperva',
            $has('x-fastly-request-id') || str_contains($value('x-served-by'), 'cache-')       => 'Fastly',
            str_contains($server, 'akamai') || $hasPrefix('x-akamai')                          => 'Akamai',
            $has('x-qc-pop') || $has('x-qc-cache')                                             => 'QUIC.cloud',
            str_contains($server, 'bunnycdn') || $has('cdn-pullzone')                          => 'BunnyCDN',
            $hasPrefix('x-hcdn')                                                               => 'Hostinger CDN',
            default                                                                            => null,
        };

        $platform = match (true) {
            str_contains($value('platform'), 'hostinger') || $hasPrefix('x-hcdn')                                   => 'Hostinger',
            $hasPrefix('x-kinsta') || $has('ki-cache-type') || $has('ki-edge')                                     => 'Kinsta',
            $has('wpe-backend') || $hasPrefix('x-wpe') || str_contains($value('x-powered-by'), 'wp engine')         => 'WP Engine',
            (bool) preg_match('/^[a-f0-9]{32}$/', $value('host-header')) || $hasPrefix('x-sg-') || $has('x-proxy-cache-info') => 'SiteGround',
            $has('x-pantheon-styx-hostname') || $has('x-styx-req-id')                                               => 'Pantheon',
            $hasPrefix('x-fw-')                                                                                     => 'Flywheel',
            $has('x-hacker') || str_contains($value('host-header'), 'wordpress.com') || str_contains($value('x-ac'), 'atomic') => 'WordPress.com',
            $hasPrefix('x-shopify') || $has('x-shopid') || $has('x-sorting-hat-shopid')                            => 'Shopify',
            $has('x-wix-request-id') || str_contains($server, 'pepyaka')                                            => 'Wix',
            str_contains($server, 'squarespace')                                                                    => 'Squarespace',
            $has('x-nf-request-id') || str_contains($server, 'netlify')                                             => 'Netlify',
            $has('x-vercel-id') || str_contains($server, 'vercel')                                                  => 'Vercel',
            $has('x-github-request-id') || str_contains($server, 'github.com')                                      => 'GitHub Pages',
            default                                                                                                 => null,
        };

        $isCdnServer = $server !== '' && (bool) preg_match('/cloudflare|akamai|cloudfront|sucuri|bunnycdn|fastly|varnish|squarespace|pepyaka|netlify|vercel|github/', $server);
        $serverSoftware = $server === '' || $isCdnServer ? null : mb_substr((string) $headers['server'], 0, 60);
        $poweredBy = isset($headers['x-powered-by']) && $headers['x-powered-by'] !== '' ? mb_substr((string) $headers['x-powered-by'], 0, 60) : null;

        return ['cdn' => $cdn, 'platform' => $platform, 'server' => $serverSoftware, 'powered_by' => $poweredBy];
    }

    /**
     * First signature name matching any text (texts in priority order).
     *
     * @param array<string, array<int, string>> $signatures
     * @param array<int|string, string> $texts
     */
    private static function match(array $signatures, array $texts): ?string
    {
        foreach ($texts as $text) {
            $text = strtolower((string) $text);
            if ($text === '') {
                continue;
            }
            foreach ($signatures as $name => $needles) {
                foreach ($needles as $needle) {
                    if (preg_match('/(?<![a-z0-9])' . preg_quote($needle, '/') . '/', $text)) {
                        return $name;
                    }
                }
            }
        }
        return null;
    }
}
