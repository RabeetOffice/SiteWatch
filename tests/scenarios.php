<?php

declare(strict_types=1);

/**
 * Monitoring engine scenario tests (no database required).
 *
 *   php tests/scenarios.php            # local fixture scenarios
 *   php tests/scenarios.php --network  # also run live SSL scenarios against badssl.com
 *
 * Runs the fixture server, probes every failure scenario through the real WebsiteMonitor +
 * StatusClassifier and asserts the classified status.
 */

use App\Monitoring\ErrorDetector;
use App\Monitoring\MonitorManager;
use App\Monitoring\SsrfGuard;
use App\Monitoring\Status;
use App\Monitoring\StatusClassifier;
use App\Monitoring\WebsiteMonitor;
use Tests\Fixtures\FixtureServer;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

$network = in_array('--network', $argv, true);
$server = new FixtureServer();
$server->start();
$base = $server->baseUrl();

$options = [
    'connect_timeout' => 3,
    'timeout'         => 3,
    'max_redirects'   => 5,
    'user_agent'      => 'SiteWatch-Test/1.0',
    'verify'          => MonitorManager::caBundlePath() ?? true,
];
$monitorPrivate = new WebsiteMonitor(new SsrfGuard(true), $options);
$monitorPublic = new WebsiteMonitor(new SsrfGuard(false), $options);
$classifier = new StatusClassifier(new ErrorDetector(), ['moderate' => 500, 'slow' => 1000, 'critical' => 2500]);
$website = ['id' => 1, 'url' => $base . '/', 'checks_json' => null];

$scenarios = [
    ['Healthy HTTP 200 website',                 $base . '/ok',                 Status::ONLINE,               null],
    ['HTTP 404 homepage',                        $base . '/404',                Status::HTTP_ERROR,           404],
    ['HTTP 500',                                 $base . '/500',                Status::HTTP_500,             500],
    ['HTTP 502',                                 $base . '/502',                Status::HTTP_502,             502],
    ['HTTP 503',                                 $base . '/503',                Status::HTTP_503,             503],
    ['HTTP 504',                                 $base . '/504',                Status::HTTP_504,             504],
    ['WordPress critical error returning 500',   $base . '/critical-500',       Status::CRITICAL_ERROR,       500],
    ['WordPress critical error returning 200',   $base . '/critical-200',       Status::CRITICAL_ERROR,       200],
    ['Database connection error',                $base . '/db-error',           Status::DATABASE_ERROR,       200],
    ['Maintenance mode',                         $base . '/maintenance',        Status::MAINTENANCE,          503],
    ['Exposed PHP fatal error',                  $base . '/fatal',              Status::CRITICAL_ERROR,       200],
    ['Blog post mentioning errors (no false positive)', $base . '/blog-post',  Status::ONLINE,               200],
    ['Slow response',                            $base . '/slow',               Status::SLOW,                 200],
    ['Critically slow response',                 $base . '/very-slow',          Status::CRITICAL_PERFORMANCE, 200],
    ['Timeout (must not be classified as slow)', $base . '/timeout',            Status::TIMEOUT,              null],
    ['Redirect chain (2 hops)',                  $base . '/redirect-chain',     Status::ONLINE,               200],
    ['Relative redirect',                        $base . '/redirect-relative',  Status::ONLINE,               200],
    ['Redirect loop',                            $base . '/redirect-loop',      Status::REDIRECT_ERROR,       null],
    ['Too many redirects',                       $base . '/redirect-many',      Status::REDIRECT_ERROR,       null],
    ['HTTP 403 (bot protection) is a warning',   $base . '/forbidden',          Status::WARNING,              403],
    ['Server error page with HTTP 200',          $base . '/server-error-page',  Status::HTTP_500,             200],
    ['Empty body',                               $base . '/empty',              Status::ONLINE,               200],
    ['Connection refused',                       'http://127.0.0.1:8090/',      Status::DOWN,                 null],
    ['DNS failure',                              'http://nonexistent-host.invalid/', Status::DNS_ERROR,        null],
];

$pass = 0;
$fail = 0;
$line = static function (string $status, string $name, string $detail): void {
    printf("  [%s] %-52s %s\n", $status, $name, $detail);
};

echo "\nMonitoring scenarios (fixture server at {$base})\n";
foreach ($scenarios as [$name, $url, $expected, $expectedHttp]) {
    $probe = $monitorPrivate->probe($url);
    $result = $classifier->classify($probe, ['id' => 1, 'url' => $url, 'checks_json' => null]);
    $ok = $result->status === $expected && ($expectedHttp === null || $result->httpStatus === $expectedHttp);
    $detail = sprintf('%s http=%s %sms%s', $result->status, $result->httpStatus ?? '-', $result->responseTime, $result->errorMessage ? ' — ' . str_limit($result->errorMessage, 70) : '');
    if ($name === 'Redirect chain (2 hops)' && $result->redirectCount !== 2) {
        $ok = false;
        $detail .= ' (redirects=' . $result->redirectCount . ')';
    }
    $ok ? $pass++ : $fail++;
    $line($ok ? 'PASS' : 'FAIL', $name, $ok ? $detail : "expected {$expected}" . ($expectedHttp ? "/{$expectedHttp}" : '') . " got {$detail}");
    if ($expected === Status::TIMEOUT) {
        // The single-threaded fixture server is still sleeping after the client gave up.
        usleep(3500000);
    }
}

echo "\nSSRF protection\n";
$ssrf = [
    ['Loopback target blocked',        $base . '/ok'],
    ['Private IPv4 blocked',           'http://192.168.1.10/'],
    ['Cloud metadata blocked',         'http://169.254.169.254/latest/meta-data/'],
    ['localhost hostname blocked',     'http://localhost/'],
    ['IPv6 loopback blocked',          'http://[::1]/'],
];
foreach ($ssrf as [$name, $url]) {
    $probe = $monitorPublic->probe($url);
    $result = $classifier->classify($probe, ['id' => 1, 'url' => $url, 'checks_json' => null]);
    $ok = $probe->errorKind === 'blocked_target' && $result->status === Status::WARNING && !$result->isFailure;
    $ok ? $pass++ : $fail++;
    $line($ok ? 'PASS' : 'FAIL', $name, $probe->errorKind . ' — ' . str_limit((string) $probe->errorMessage, 80));
}

echo "\nConcurrency\n";
$batch = [];
foreach (['/ok', '/500', '/critical-200', '/404', '/db-error', '/ok'] as $i => $p) {
    $batch[] = ['id' => $i + 1, 'url' => $base . $p];
}
$t = microtime(true);
$results = $monitorPrivate->probeMany($batch, 15);
$ms = (int) round((microtime(true) - $t) * 1000);
$ok = count($results) === count($batch);
foreach ($batch as $site) {
    $ok = $ok && isset($results[$site['id']]);
}
$ok ? $pass++ : $fail++;
$line($ok ? 'PASS' : 'FAIL', 'Batch of 6 websites returns 6 results', count($results) . " results in {$ms}ms");

if ($network) {
    echo "\nLive SSL scenarios (network)\n";
    foreach ([
        ['Expired certificate',     'https://expired.badssl.com/',     Status::SSL_ERROR],
        ['Self-signed certificate', 'https://self-signed.badssl.com/', Status::SSL_ERROR],
        ['Wrong host certificate',  'https://wrong.host.badssl.com/',  Status::SSL_ERROR],
        ['Valid certificate',       'https://example.com/',            Status::ONLINE],
    ] as [$name, $url, $expected]) {
        $probe = (new WebsiteMonitor(new SsrfGuard(false), ['timeout' => 20, 'connect_timeout' => 10] + $options))->probe($url);
        // Production thresholds: live network latency must not turn a valid site into a performance failure.
        $result = (new StatusClassifier(new ErrorDetector()))->classify($probe, ['id' => 1, 'url' => $url, 'checks_json' => null]);
        $ok = $result->status === $expected;
        $ok ? $pass++ : $fail++;
        $line($ok ? 'PASS' : 'FAIL', $name, $result->status . ' — ' . str_limit((string) $result->errorMessage, 70));
    }
}

$server->stop();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
