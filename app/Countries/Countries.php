<?php

declare(strict_types=1);

namespace App\Countries;

/**
 * Countries the availability check can test from. The default list covers where the agency's readers are,
 * the main regions, and three countries known for blocking websites at network level.
 */
final class Countries
{
    /** Code => [name, group]. Groups decide the order on screen. */
    public const ALL = [
        'US' => ['United States', 'readers'],
        'GB' => ['United Kingdom', 'readers'],
        'IE' => ['Ireland', 'readers'],
        'AU' => ['Australia', 'readers'],
        'CA' => ['Canada', 'readers'],
        'DE' => ['Germany', 'regions'],
        'IN' => ['India', 'regions'],
        'PK' => ['Pakistan', 'regions'],
        'AE' => ['United Arab Emirates', 'regions'],
        'SG' => ['Singapore', 'regions'],
        'BR' => ['Brazil', 'regions'],
        'ZA' => ['South Africa', 'regions'],
        'TR' => ['Turkey', 'blocking'],
        'RU' => ['Russia', 'blocking'],
        'CN' => ['China', 'blocking'],
        // Also selectable in settings.
        'NZ' => ['New Zealand', 'readers'],
        'FR' => ['France', 'regions'],
        'NL' => ['Netherlands', 'regions'],
        'JP' => ['Japan', 'regions'],
        'NG' => ['Nigeria', 'regions'],
        'SA' => ['Saudi Arabia', 'regions'],
        'ID' => ['Indonesia', 'regions'],
        'IR' => ['Iran', 'blocking'],
    ];

    public const DEFAULT = 'US,GB,IE,AU,CA,DE,IN,PK,AE,SG,BR,ZA,TR,RU,CN';

    /** At most this many countries per website run (one test each, plus re-checks). */
    public const MAX = 15;

    public static function name(string $code): string
    {
        return self::ALL[strtoupper($code)][0] ?? strtoupper($code);
    }

    /**
     * Parse the stored comma-separated list: known codes only, no duplicates, at most MAX.
     *
     * @return list<string>
     */
    public static function parse(?string $list): array
    {
        $codes = [];
        foreach (preg_split('/[\s,;]+/', strtoupper((string) $list)) ?: [] as $code) {
            if (isset(self::ALL[$code]) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        return array_slice($codes === [] ? self::parse(self::DEFAULT) : $codes, 0, self::MAX);
    }

    /**
     * @return list<array{code: string, name: string, group: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::ALL as $code => [$name, $group]) {
            $out[] = ['code' => $code, 'name' => $name, 'group' => $group];
        }
        return $out;
    }
}
