<?php

declare(strict_types=1);

namespace App\Countries;

/**
 * Turns the probes of one run into a result per country.
 *
 * A problem in one country only counts as country-specific when most other countries could open the site; when
 * most fail, the site is down (which the main monitor already handles). The classifier is deliberately cautious:
 * "blocked" needs a sign of interference (a legal-block status, a reset connection, a DNS answer that points
 * nowhere or a block page); otherwise a failure is "unreachable".
 */
final class CountryClassifier
{
    public const REACHABLE = 'reachable';
    public const SLOW = 'slow';
    public const GEO_BLOCKED = 'geo_blocked';
    public const BLOCKED = 'blocked';
    public const UNREACHABLE = 'unreachable';
    public const DOWN = 'down';
    public const NO_PROBE = 'no_probe';

    /** Results that are a problem in that country. */
    public const PROBLEMS = [self::GEO_BLOCKED, self::BLOCKED, self::UNREACHABLE];

    public const LABELS = [
        self::REACHABLE   => 'Reachable',
        self::SLOW        => 'Slow',
        self::GEO_BLOCKED => 'Blocked by the site',
        self::BLOCKED     => 'Possibly blocked',
        self::UNREACHABLE => 'Unreachable',
        self::DOWN        => 'Down everywhere',
        self::NO_PROBE    => 'No test server',
    ];

    public const TONES = [
        self::REACHABLE   => 'success',
        self::SLOW        => 'warning',
        self::GEO_BLOCKED => 'warning',
        self::BLOCKED     => 'danger',
        self::UNREACHABLE => 'danger',
        self::DOWN        => 'danger',
        self::NO_PROBE    => 'neutral',
    ];

    public const EXPLANATIONS = [
        self::REACHABLE   => 'The site opened normally from this country.',
        self::SLOW        => 'The site opened, but slower than the slow threshold.',
        self::GEO_BLOCKED => 'The site (or its firewall or CDN) refused visitors from this country, for example with a country rule in Wordfence or Cloudflare. Usually a setting on your side.',
        self::BLOCKED     => 'Signs of blocking by the country\'s network: a legal-block answer, a reset connection, a DNS answer that points nowhere or a block page. Checked from two networks.',
        self::UNREACHABLE => 'The site did not answer from this country on two networks, while other countries could open it.',
        self::DOWN        => 'The site failed from most countries, so this is an outage rather than a country problem.',
        self::NO_PROBE    => 'No free test server was available in this country for this run.',
    ];

    /** Errors that point at interference on the path rather than at the site. */
    private const RESET_ERRORS = ['ECONNRESET', 'EPIPE', 'connection reset', 'Connection reset', 'socket hang up'];

    /** Words that appear on common government / ISP block pages. */
    private const BLOCK_PAGE_WORDS = [
        'blocked by order', 'access to this website has been blocked', 'this site has been blocked', 'site is blocked',
        'erişim engellendi', 'bu internet sitesi', 'доступ ограничен', 'заблокирован', 'роскомнадзор',
        'pta has blocked', 'this url has been blocked', 'the site you are trying to access has been blocked',
        'blocked as per', 'website is not accessible', 'restricted by the government',
    ];

    /**
     * @param array<string, Probe>  $first  Country => first probe.
     * @param array<string, Probe>  $second Country => second probe on another network (only for first-probe problems).
     * @param list<string>          $countries All countries requested (missing ones get no_probe).
     * @return array<string, array{result: string, probe: ?Probe, confirmed: bool}>
     */
    public function classify(array $first, array $second, array $countries, int $slowMs): array
    {
        $answered = array_filter($first, static fn (Probe $p): bool => $p->isOk());
        $tested = count($first);
        // Block-page wording only counts when a few countries see it; on most of them it is the site's own text.
        $blockPages = count(array_filter($first, fn (Probe $p): bool => $this->looksLikeBlockPage($p)));
        $readBody = $blockPages <= max(1, (int) floor($tested / 3));
        // Most countries failing is an outage, not a country problem.
        $outage = $tested >= 3 && count($answered) < $tested * 0.4;

        $out = [];
        foreach ($countries as $country) {
            $probe = $first[$country] ?? null;
            if ($probe === null) {
                $out[$country] = ['result' => self::NO_PROBE, 'probe' => null, 'confirmed' => false];
                continue;
            }
            $result = $this->single($probe, $slowMs, $readBody);
            if (!in_array($result, self::PROBLEMS, true)) {
                $out[$country] = ['result' => $result, 'probe' => $probe, 'confirmed' => false];
                continue;
            }
            if ($outage) {
                $out[$country] = ['result' => self::DOWN, 'probe' => $probe, 'confirmed' => false];
                continue;
            }
            // Country-specific problem: a second probe on another network must agree.
            $again = $second[$country] ?? null;
            if ($again === null) {
                $out[$country] = ['result' => $result, 'probe' => $probe, 'confirmed' => false];
                continue;
            }
            $secondResult = $this->single($again, $slowMs, $readBody);
            if (in_array($secondResult, self::PROBLEMS, true)) {
                // Both failed; the stronger signal wins (blocked > geo-blocked > unreachable).
                $rank = [self::BLOCKED => 3, self::GEO_BLOCKED => 2, self::UNREACHABLE => 1];
                $final = $rank[$secondResult] > $rank[$result] ? $secondResult : $result;
                $out[$country] = ['result' => $final, 'probe' => $final === $secondResult ? $again : $probe, 'confirmed' => true];
            } else {
                // The first probe's network had a problem; the country is fine.
                $out[$country] = ['result' => $secondResult, 'probe' => $again, 'confirmed' => false];
            }
        }
        return $out;
    }

    /**
     * Result of one probe on its own.
     */
    public function single(Probe $probe, int $slowMs, bool $readBody = true): string
    {
        // Many ISPs serve their block page with a 200, so the page is checked before the status code.
        if ($readBody && $this->looksLikeBlockPage($probe)) {
            return self::BLOCKED;
        }
        if ($probe->isOk()) {
            return $probe->responseMs !== null && $probe->responseMs >= $slowMs ? self::SLOW : self::REACHABLE;
        }
        if ($probe->answered && $probe->statusCode !== null) {
            if ($probe->statusCode === 451) {
                return self::BLOCKED;
            }
            if (in_array($probe->statusCode, [401, 403, 406, 429, 444], true)) {
                return self::GEO_BLOCKED;
            }
            // 5xx or another 4xx only in this country: the site is not usable there.
            return self::UNREACHABLE;
        }
        if ($probe->resolvedIp !== null && self::isBogon($probe->resolvedIp)) {
            return self::BLOCKED;
        }
        $error = (string) $probe->error;
        foreach (self::RESET_ERRORS as $needle) {
            if (str_contains($error, $needle)) {
                return self::BLOCKED;
            }
        }
        if (str_contains($error, 'ENOTFOUND') || str_contains($error, 'EAI_AGAIN') || stripos($error, 'SERVFAIL') !== false || stripos($error, 'NXDOMAIN') !== false) {
            // The name resolves elsewhere but not here.
            return self::BLOCKED;
        }
        return self::UNREACHABLE;
    }

    private function looksLikeBlockPage(Probe $probe): bool
    {
        if ($probe->body === '') {
            return false;
        }
        $body = mb_strtolower(mb_substr($probe->body, 0, 20000));
        foreach (self::BLOCK_PAGE_WORDS as $word) {
            if (str_contains($body, $word)) {
                return true;
            }
        }
        return false;
    }

    /** Whether a firewall or CDN answered instead of the site (shown in the details). */
    public static function firewallName(Probe $probe): ?string
    {
        $h = $probe->headers;
        if (isset($h['cf-ray']) || str_contains(strtolower($h['server'] ?? ''), 'cloudflare')) {
            return 'Cloudflare';
        }
        if (isset($h['x-sucuri-id']) || isset($h['x-sucuri-block'])) {
            return 'Sucuri';
        }
        if (isset($h['x-iinfo'])) {
            return 'Imperva';
        }
        if (stripos($probe->body, 'wordfence') !== false) {
            return 'Wordfence';
        }
        return null;
    }

    /** Addresses a public website can never have: DNS answers like these mean the name was redirected. */
    public static function isBogon(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($ip === '0.0.0.0' || $ip === '::') {
            return true;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
