<?php

declare(strict_types=1);

namespace App\Performance;

use App\Domains\HttpClient;
use RuntimeException;

/**
 * Google PageSpeed Insights API v5.
 *
 * PageSpeed is the only way to obtain Core Web Vitals from a PHP host: LCP, CLS and INP describe what a
 * real browser does while rendering, and nothing measurable with cURL can stand in for them. The API is
 * free; an API key is optional and only raises the request quota.
 *
 * Every response carries two independent sets of numbers:
 *
 *  - **Lab**, from the Lighthouse run PageSpeed performs on demand in a throttled emulated browser. Always
 *    present, reproducible, and the right thing for spotting a regression. Lighthouse cannot measure INP
 *    (there is no user to interact), so it reports Total Blocking Time as the closest stand-in.
 *  - **Field**, the 75th percentile of real Chrome users over the last 28 days (CrUX). This is what Google
 *    actually ranks on and the only place INP exists — but it only appears once a URL, or failing that its
 *    origin, has enough traffic, so it is frequently absent for smaller sites.
 */
final class PageSpeedClient
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    public const STRATEGIES = ['mobile', 'desktop'];

    /** Lighthouse runs a real browser against the target, so this is slow by nature. */
    private const TIMEOUT = 90;

    public function __construct(private readonly HttpClient $http, private readonly string $apiKey = '')
    {
    }

    public static function create(string $userAgent, ?string $caFile, string $apiKey = ''): self
    {
        return new self(new HttpClient($userAgent, $caFile, self::TIMEOUT), $apiKey);
    }

    /**
     * Run PageSpeed against one URL for one strategy.
     *
     * @param bool $wantScreenshot Also return the final render as raw image bytes.
     */
    public function analyse(string $url, string $strategy, bool $wantScreenshot = false): VitalsResult
    {
        if (!in_array($strategy, self::STRATEGIES, true)) {
            throw new RuntimeException('Unknown PageSpeed strategy: ' . $strategy);
        }

        $query = [
            'url'      => $url,
            'strategy' => $strategy,
            'category' => 'performance',
        ];
        if ($this->apiKey !== '') {
            $query['key'] = $this->apiKey;
        }

        $response = $this->http->getJson(self::ENDPOINT . '?' . http_build_query($query));
        $data = $response['data'];

        if ($response['error'] !== null && !is_array($data)) {
            throw new RuntimeException('PageSpeed request failed: ' . $this->sanitize($response["error"]));
        }
        if (!is_array($data)) {
            throw new RuntimeException('PageSpeed returned an unreadable response (HTTP ' . $response['status'] . ').');
        }
        if (isset($data['error'])) {
            throw new RuntimeException('PageSpeed error: ' . $this->sanitize(self::apiError($data["error"], $response["status"])));
        }
        if (!isset($data['lighthouseResult'])) {
            throw new RuntimeException('PageSpeed returned no Lighthouse result (HTTP ' . $response['status'] . ').');
        }

        return VitalsResult::fromApi($strategy, $data, $wantScreenshot);
    }

    /**
     * Google nests the useful text under error.errors[].message; error.message alone is often just
     * "Unable to process request. Please wait a while and try again."
     *
     * @param mixed $error
     */
    private static function apiError(mixed $error, int $status): string
    {
        if (!is_array($error)) {
            return 'HTTP ' . $status;
        }
        $parts = [];
        if (isset($error['message']) && is_scalar($error['message'])) {
            $parts[] = (string) $error['message'];
        }
        $detail = $error['errors'][0]['message'] ?? null;
        if (is_scalar($detail) && (string) $detail !== '' && !in_array((string) $detail, $parts, true)) {
            $parts[] = (string) $detail;
        }
        if ($parts === []) {
            $parts[] = 'HTTP ' . $status;
        }
        if ((int) ($error['code'] ?? 0) === 429 || $status === 429) {
            $parts[] = 'The PageSpeed quota is exhausted — add a free API key or lower the check frequency.';
        }
        return implode(' — ', $parts);
    }

    /** Keep the API key out of logs and the interface. */
    private function sanitize(string $text): string
    {
        if ($this->apiKey !== '') {
            $text = str_replace($this->apiKey, '[key]', $text);
        }
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, 400);
    }
}
