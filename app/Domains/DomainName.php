<?php

declare(strict_types=1);

namespace App\Domains;

use App\Core\UrlNormalizer;

/**
 * Registrable-domain helpers ("www.shop.example.co.uk" -> "example.co.uk") without a full Public Suffix List.
 * Generic TLDs and the common second-level registries (co.uk, com.au, co.nz, ...) are handled here; for other
 * multi-level suffixes DomainInspector also tries the next longer name when a registry reports "not found".
 */
final class DomainName
{
    /** Second-level labels under which registrations happen, per country TLD. */
    private const SECOND_LEVEL = [
        'uk' => ['co', 'org', 'me', 'ltd', 'plc', 'net', 'sch', 'ac', 'gov', 'nhs', 'police'],
        'au' => ['com', 'net', 'org', 'edu', 'gov', 'asn', 'id'],
        'nz' => ['co', 'org', 'net', 'ac', 'govt', 'school', 'geek', 'kiwi', 'maori'],
        'za' => ['co', 'org', 'net', 'gov', 'edu', 'web'],
        'in' => ['co', 'net', 'org', 'firm', 'gen', 'ind', 'ac', 'edu', 'gov', 'res'],
        'pk' => ['com', 'net', 'org', 'edu', 'gov', 'biz', 'web', 'fam'],
        'jp' => ['co', 'ne', 'or', 'ac', 'go', 'gr', 'ed', 'lg'],
        'br' => ['com', 'net', 'org', 'gov', 'edu', 'art', 'blog', 'eco', 'ind', 'inf', 'log', 'med', 'tur', 'app', 'dev'],
        'mx' => ['com', 'org', 'net', 'edu', 'gob'],
        'ar' => ['com', 'org', 'net', 'gob', 'edu'],
        'cn' => ['com', 'net', 'org', 'gov', 'edu', 'ac'],
        'hk' => ['com', 'org', 'net', 'edu', 'gov', 'idv'],
        'sg' => ['com', 'net', 'org', 'edu', 'gov', 'per'],
        'my' => ['com', 'net', 'org', 'edu', 'gov', 'name'],
        'ng' => ['com', 'org', 'net', 'edu', 'gov', 'name', 'sch'],
        'ke' => ['co', 'or', 'ne', 'go', 'ac', 'sc', 'me', 'info'],
        'tr' => ['com', 'net', 'org', 'edu', 'gov', 'biz', 'info', 'gen', 'web'],
        'il' => ['co', 'org', 'net', 'ac', 'gov', 'muni'],
        'kr' => ['co', 'or', 'ne', 'ac', 'go', 're', 'pe'],
        'tw' => ['com', 'net', 'org', 'edu', 'gov', 'idv'],
        'ph' => ['com', 'net', 'org', 'edu', 'gov'],
        'id' => ['co', 'or', 'net', 'ac', 'go', 'web', 'my', 'sch', 'biz'],
        'th' => ['co', 'or', 'net', 'ac', 'go', 'in', 'mi'],
        'vn' => ['com', 'net', 'org', 'edu', 'gov', 'biz', 'info'],
        'eg' => ['com', 'net', 'org', 'edu', 'gov'],
        'sa' => ['com', 'net', 'org', 'edu', 'gov', 'med', 'sch'],
        'ae' => ['co', 'net', 'org', 'ac', 'gov', 'sch'],
        'gh' => ['com', 'org', 'edu', 'gov'],
        'ug' => ['co', 'or', 'ac', 'sc', 'go', 'ne'],
        'tz' => ['co', 'or', 'ne', 'ac', 'go', 'sc', 'me'],
        'zw' => ['co', 'org', 'ac', 'gov'],
        'bd' => ['com', 'net', 'org', 'edu', 'gov', 'ac'],
        'lk' => ['com', 'net', 'org', 'edu', 'gov', 'web'],
        'np' => ['com', 'net', 'org', 'edu', 'gov'],
        'cy' => ['com', 'net', 'org', 'ac', 'gov'],
        'mt' => ['com', 'net', 'org', 'edu', 'gov'],
        'co' => ['com', 'net', 'org', 'edu', 'gov', 'nom'],
        'pe' => ['com', 'net', 'org', 'edu', 'gob', 'nom'],
        'es' => ['com', 'org', 'nom', 'edu', 'gob'],
        'pl' => ['com', 'net', 'org', 'info', 'biz', 'edu', 'gov'],
        'ua' => ['com', 'net', 'org', 'in', 'edu', 'gov'],
        'gr' => ['com', 'net', 'org', 'edu', 'gov'],
        'pt' => ['com', 'org', 'edu', 'gov'],
        'je' => ['co', 'org', 'net'],
        'gg' => ['co', 'org', 'net'],
        'im' => ['co', 'ltd', 'plc', 'net', 'org'],
    ];

    /**
     * Accept a domain name or URL typed by a user and return the ASCII host, or null when it is not a domain.
     */
    public static function fromInput(string $input): ?string
    {
        $url = UrlNormalizer::normalize(trim($input));
        if ($url === null) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || !str_contains($host, '.')) {
            return null;
        }
        return $host;
    }

    public static function registrable(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $labels = explode('.', $host);
        $count = count($labels);
        if ($count <= 2) {
            return $host;
        }
        $tld = $labels[$count - 1];
        $second = $labels[$count - 2];
        $depth = in_array($second, self::SECOND_LEVEL[$tld] ?? [], true) ? 3 : 2;
        return implode('.', array_slice($labels, -$depth));
    }

    /**
     * Names to query at the registry, most likely first.
     *
     * @return array<int, string>
     */
    public static function candidates(string $host): array
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $registrable = self::registrable($host);
        $candidates = [$registrable];
        $labels = explode('.', $host);
        $depth = substr_count($registrable, '.') + 1;
        if (count($labels) > $depth) {
            $longer = implode('.', array_slice($labels, -($depth + 1)));
            if (!str_starts_with($longer, 'www.')) {
                $candidates[] = $longer;
            }
        }
        return $candidates;
    }

    public static function tld(string $domain): string
    {
        $parts = explode('.', strtolower(rtrim($domain, '.')));
        return (string) end($parts);
    }

    /**
     * Human-readable form of an IDN (xn--) domain, when the intl extension is available.
     */
    public static function toUnicode(string $domain): string
    {
        if (!str_contains($domain, 'xn--') || !function_exists('idn_to_utf8')) {
            return $domain;
        }
        $unicode = idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);
        return is_string($unicode) && $unicode !== '' ? $unicode : $domain;
    }
}
