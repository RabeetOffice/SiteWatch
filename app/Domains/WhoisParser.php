<?php

declare(strict_types=1);

namespace App\Domains;

/**
 * Parses port-43 WHOIS text into registration facts.
 *
 * Handles the ICANN "Key: Value" layout (gTLDs, .ie, .io, ...), indented section blocks (Nominet .uk, EURid
 * .eu: "Registrar:" followed by indented lines) and terse registries (DENIC). Redacted values are ignored.
 */
final class WhoisParser
{
    private const NOT_FOUND = [
        '/^\s*(no match|not found|no data found|no entries found|no object found|nothing found|domain not found|object does not exist)/im',
        '/^\s*status:\s*(free|available)\s*$/im',
        '/is available for (registration|purchase)/i',
        '/^\s*%*\s*no such domain/im',
    ];

    private const KEYS = [
        'created'  => ['creation date', 'created', 'created on', 'registered on', 'relevant dates registered on', 'registration date', 'registration time', 'domain registration date', 'registered', 'domain create date', 'first registration date'],
        'expires'  => ['registry expiry date', 'registrar registration expiration date', 'expiry date', 'relevant dates expiry date', 'expiration date', 'expires on', 'expires', 'expire date', 'expiration time', 'paid-till', 'renewal date', 'domain expiration date'],
        'updated'  => ['updated date', 'last updated', 'relevant dates last updated', 'last modified', 'changed', 'modified', 'last update', 'domain last updated date'],
        'registrar' => ['registrar', 'sponsoring registrar', 'registrar name', 'registrar organization', 'registrar organisation'],
        'registrar_url' => ['registrar url', 'registrar website', 'registrar web'],
        'registrar_whois' => ['registrar whois server', 'whois server'],
        'abuse_email' => ['registrar abuse contact email', 'abuse contact email'],
        'nameservers' => ['name server', 'nserver', 'nameserver', 'nameservers', 'name servers', 'dns', 'host name'],
        'statuses' => ['domain status', 'status', 'registration status', 'state'],
        'registrant' => ['registrant organization', 'registrant organisation', 'registrant name', 'registrant', 'org', 'owner'],
        'registrant_country' => ['registrant country', 'registrant country code'],
        'dnssec' => ['dnssec', 'signed'],
    ];

    /**
     * @return array{found: bool, registrar: ?string, registrar_url: ?string, registrar_whois: ?string, abuse_email: ?string, registered_at: ?string, expires_at: ?string, updated_at: ?string, nameservers: array<int, string>, statuses: array<int, string>, registrant: ?string, registrant_country: ?string, dnssec: ?bool}
     */
    public static function parse(string $raw): array
    {
        $fields = self::fields($raw);
        $first = static function (string $name) use ($fields): ?string {
            foreach (self::KEYS[$name] as $key) {
                $values = $fields[$key] ?? [];
                // One redaction marker hides the whole field (e.g. "NOT DISCLOSED!" followed by a hint line).
                foreach ($values as $value) {
                    if (self::isRedacted($value)) {
                        continue 2;
                    }
                }
                foreach ($values as $value) {
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
            return null;
        };
        $all = static function (string $name) use ($fields): array {
            foreach (self::KEYS[$name] as $key) {
                if (!empty($fields[$key])) {
                    return $fields[$key];
                }
            }
            return [];
        };

        $registrar = $first('registrar');
        if ($registrar !== null) {
            $registrar = trim((string) preg_replace('/\s*\[tag\s*=.*?\]\s*$/i', '', $registrar));
        }

        $nameservers = [];
        foreach ($all('nameservers') as $value) {
            $host = strtolower(rtrim((string) strtok($value, " \t("), '.'));
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)) {
                $nameservers[] = $host;
            }
        }

        $statuses = [];
        foreach ($all('statuses') as $value) {
            $value = trim((string) preg_replace('#\s*\(?https?://\S+\)?#', '', $value));
            if ($value !== '') {
                $statuses[] = $value;
            }
        }

        $dnssec = null;
        $dnssecValue = $first('dnssec');
        if ($dnssecValue !== null) {
            $v = strtolower($dnssecValue);
            $dnssec = str_contains($v, 'unsigned') || in_array($v, ['no', 'false', 'inactive'], true) ? false : (preg_match('/signed|yes|true|active/', $v) ? true : null);
        }

        $found = !self::matchesNotFound($raw) && ($registrar !== null || $nameservers !== [] || $first('created') !== null || $first('expires') !== null);

        return [
            'found'              => $found,
            'registrar'          => $registrar !== null ? mb_substr($registrar, 0, 190) : null,
            'registrar_url'      => self::url($first('registrar_url')),
            'registrar_whois'    => $first('registrar_whois'),
            'abuse_email'        => filter_var((string) $first('abuse_email'), FILTER_VALIDATE_EMAIL) ?: null,
            'registered_at'      => self::date($first('created')),
            'expires_at'         => self::date($first('expires')),
            'updated_at'         => self::date($first('updated')),
            'nameservers'        => array_values(array_unique($nameservers)),
            'statuses'           => array_values(array_unique($statuses)),
            'registrant'         => ($r = $first('registrant')) !== null ? mb_substr($r, 0, 190) : null,
            'registrant_country' => $first('registrant_country'),
            'dnssec'             => $dnssec,
        ];
    }

    /**
     * Collect "key: value" pairs. Keys are lower-cased; indented lines under a bare "Section:" line become
     * "section key" pairs, or values of "section" when they have no key.
     *
     * @return array<string, array<int, string>>
     */
    public static function fields(string $raw): array
    {
        $fields = [];
        $section = null;
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (trim($line) === '' ) {
                $section = null;
                continue;
            }
            $trimmed = trim($line);
            if ($trimmed[0] === '%' || $trimmed[0] === '#' || str_starts_with($trimmed, '>>>') || str_starts_with($trimmed, '--')) {
                continue;
            }
            $indented = (bool) preg_match('/^\s+/', $line);

            if (preg_match('/^([A-Za-z][A-Za-z0-9 \/().\-_]{0,60}?):\s*(.*)$/', $trimmed, $m) && !preg_match('#^https?$#i', $m[1])) {
                $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $m[1])));
                $value = trim($m[2]);
                if ($value === '' ) {
                    // "Registrar:" style heading; indented lines that follow belong to it.
                    $section = $key;
                    continue;
                }
                if ($indented && $section !== null) {
                    $fields[$section . ' ' . $key][] = $value;
                    continue;
                }
                $section = null;
                $fields[$key][] = $value;
                continue;
            }
            if ($indented && $section !== null) {
                $fields[$section][] = $trimmed;
            }
        }
        return $fields;
    }

    public static function matchesNotFound(string $raw): bool
    {
        foreach (self::NOT_FOUND as $pattern) {
            if (preg_match($pattern, $raw)) {
                return true;
            }
        }
        return false;
    }

    public static function isRedacted(string $value): bool
    {
        return (bool) preg_match('/redacted|not disclosed|withheld|data protected|privacy|gdpr masked|non-public|statutory masking|see privacyguardian|contact privacy|whoisguard|domains by proxy|identity protect/i', $value);
    }

    /**
     * Parse the many date formats used by registries into "Y-m-d H:i:s" (UTC).
     */
    public static function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s*\((utc|gmt)\)|\s+(utc|gmt)$/i', '', trim($value)));
        if ($value === '') {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        foreach (['Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.u\Z', \DATE_ATOM, 'Y-m-d\TH:i:sP', 'Y-m-d H:i:s', 'Y-m-d', 'd-M-Y', 'd-M-Y H:i:s', 'd.m.Y', 'd.m.Y H:i:s', 'Y.m.d', 'Y.m.d H:i:s', 'Y/m/d', 'Y/m/d H:i:s', 'd/m/Y', 'Ymd'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return self::bounded($dt);
            }
        }
        $ts = strtotime($value . (preg_match('/[+\-]\d{2}:?\d{2}$|z$/i', $value) ? '' : ' UTC'));
        return $ts !== false ? self::bounded((new \DateTimeImmutable('@' . $ts))) : null;
    }

    private static function bounded(\DateTimeImmutable $dt): ?string
    {
        $year = (int) $dt->format('Y');
        if ($year < 1985 || $year > 2200) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function url(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }
        return filter_var($value, FILTER_VALIDATE_URL) ? mb_substr($value, 0, 255) : null;
    }
}
