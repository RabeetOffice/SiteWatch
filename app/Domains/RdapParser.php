<?php

declare(strict_types=1);

namespace App\Domains;

/**
 * Extracts registration facts from an RDAP domain object (RFC 9083).
 */
final class RdapParser
{
    /**
     * @param array<string, mixed> $json
     * @return array{found: bool, registrar: ?string, registrar_url: ?string, registrar_iana_id: ?string, abuse_email: ?string, registered_at: ?string, expires_at: ?string, updated_at: ?string, nameservers: array<int, string>, statuses: array<int, string>, registrant: ?string, registrant_country: ?string, dnssec: ?bool}
     */
    public static function parse(array $json): array
    {
        $events = [];
        foreach ((array) ($json['events'] ?? []) as $event) {
            if (is_array($event) && isset($event['eventAction'], $event['eventDate'])) {
                $events[strtolower((string) $event['eventAction'])] ??= WhoisParser::date((string) $event['eventDate']);
            }
        }

        $registrar = null;
        $registrarUrl = null;
        $ianaId = null;
        $abuse = null;
        $registrant = null;
        $registrantCountry = null;

        foreach ((array) ($json['entities'] ?? []) as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $roles = self::roles($entity);
            $card = self::vcard($entity);

            if ($registrar === null && in_array('registrar', $roles, true)) {
                $registrar = $card['fn'] ?? $card['org'] ?? (isset($entity['handle']) ? (string) $entity['handle'] : null);
                $registrarUrl = $card['url'] ?? self::link($entity, 'about');
                foreach ((array) ($entity['publicIds'] ?? []) as $publicId) {
                    if (is_array($publicId) && stripos((string) ($publicId['type'] ?? ''), 'iana') !== false && isset($publicId['identifier'])) {
                        $ianaId = (string) $publicId['identifier'];
                    }
                }
                foreach ((array) ($entity['entities'] ?? []) as $child) {
                    if (is_array($child) && in_array('abuse', self::roles($child), true)) {
                        $abuse = self::vcard($child)['email'] ?? $abuse;
                    }
                }
            }

            if ($registrant === null && in_array('registrant', $roles, true)) {
                $name = $card['org'] ?? $card['fn'] ?? null;
                if ($name !== null && !WhoisParser::isRedacted($name)) {
                    $registrant = $name;
                }
                $country = $card['country'] ?? null;
                if ($country !== null && !WhoisParser::isRedacted($country)) {
                    $registrantCountry = $country;
                }
            }
        }

        $nameservers = [];
        foreach ((array) ($json['nameservers'] ?? []) as $ns) {
            $name = is_array($ns) ? strtolower(rtrim((string) ($ns['ldhName'] ?? ''), '.')) : '';
            if ($name !== '') {
                $nameservers[] = $name;
            }
        }

        $statuses = array_values(array_filter(array_map(
            static fn ($s): string => is_string($s) ? trim($s) : '',
            (array) ($json['status'] ?? [])
        )));

        $signed = $json['secureDNS']['delegationSigned'] ?? null;

        return [
            'found'              => ($json['objectClassName'] ?? '') === 'domain' || isset($json['ldhName']),
            'registrar'          => $registrar !== null && $registrar !== '' ? mb_substr($registrar, 0, 190) : null,
            'registrar_url'      => $registrarUrl !== null && preg_match('#^https?://#i', $registrarUrl) ? mb_substr($registrarUrl, 0, 255) : null,
            'registrar_iana_id'  => $ianaId,
            'abuse_email'        => filter_var((string) $abuse, FILTER_VALIDATE_EMAIL) ?: null,
            'registered_at'      => $events['registration'] ?? null,
            'expires_at'         => $events['expiration'] ?? null,
            'updated_at'         => $events['last changed'] ?? null,
            'nameservers'        => array_values(array_unique($nameservers)),
            'statuses'           => array_values(array_unique($statuses)),
            'registrant'         => $registrant !== null ? mb_substr($registrant, 0, 190) : null,
            'registrant_country' => $registrantCountry,
            'dnssec'             => is_bool($signed) ? $signed : null,
        ];
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<int, string>
     */
    private static function roles(array $entity): array
    {
        return array_map(static fn ($r): string => strtolower((string) $r), (array) ($entity['roles'] ?? []));
    }

    /**
     * First non-empty value of the interesting vCard properties (fn, org, email, url) plus the address country.
     *
     * @param array<string, mixed> $entity
     * @return array<string, string>
     */
    private static function vcard(array $entity): array
    {
        $out = [];
        $properties = $entity['vcardArray'][1] ?? null;
        if (!is_array($properties)) {
            return $out;
        }
        foreach ($properties as $property) {
            if (!is_array($property) || count($property) < 4) {
                continue;
            }
            $name = strtolower((string) $property[0]);
            $params = $property[1];
            $value = $property[3];

            if ($name === 'adr') {
                $cc = is_array($params) ? ($params['cc'] ?? null) : null;
                if (is_string($cc) && trim($cc) !== '') {
                    $out['country'] ??= strtoupper(trim($cc));
                } elseif (is_array($value) && isset($value[6]) && is_string($value[6]) && trim($value[6]) !== '') {
                    $out['country'] ??= trim($value[6]);
                }
                continue;
            }
            if (is_array($value)) {
                $value = implode(' ', array_filter($value, static fn ($v): bool => is_string($v) && $v !== ''));
            }
            $value = trim((string) (is_scalar($value) ? $value : ''));
            if ($value === '' || isset($out[$name])) {
                continue;
            }
            if ($name === 'email') {
                $value = (string) preg_replace('/^mailto:/i', '', $value);
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /** @param array<string, mixed> $entity */
    private static function link(array $entity, string $rel): ?string
    {
        foreach ((array) ($entity['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === $rel && isset($link['href'])) {
                return (string) $link['href'];
            }
        }
        return null;
    }
}
