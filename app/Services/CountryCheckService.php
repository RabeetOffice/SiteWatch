<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Countries\CheckHostClient;
use App\Countries\Countries;
use App\Countries\CountryClassifier;
use App\Countries\GlobalpingClient;
use App\Countries\JsonHttp;
use App\Countries\Probe;
use App\Countries\RateLimitException;
use App\Monitoring\MonitorManager;
use App\Notifications\NotificationManager;
use App\Repositories\CountryCheckRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\WebsiteRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Country availability: can people in other countries open a website?
 *
 * One run tests a website from one probe per country (Globalping, with check-host.net for countries Globalping
 * has no probe in), asks a second probe in each country that had a problem, classifies the results and stores
 * them. The requests run on the providers' servers, never from this one.
 */
final class CountryCheckService
{
    public function __construct(
        private readonly CountryCheckRepository $repo,
        private readonly WebsiteRepository $websites,
        private readonly SettingsRepository $settings,
        private readonly GlobalpingClient $globalping,
        private readonly CheckHostClient $checkHost,
        private readonly CountryClassifier $classifier,
        private readonly ?NotificationManager $notifications,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function create(): self
    {
        $settings = App::settings();
        $http = new JsonHttp('SiteWatch/' . (string) App::config()->get('app.version', '2') . ' (country availability)', MonitorManager::caBundlePath());
        $cache = rtrim((string) App::config()->get('app.paths.cache', dirname(__DIR__, 2) . '/storage/cache'), '/\\') . DIRECTORY_SEPARATOR . 'checkhost-nodes.json';
        return new self(
            new CountryCheckRepository(App::db()),
            new WebsiteRepository(App::db()),
            $settings,
            new GlobalpingClient($http, $settings->getString('globalping_token')),
            new CheckHostClient($http, $cache),
            new CountryClassifier(),
            new NotificationManager($settings, new NotificationRepository(App::db()), App::logger('notifications')),
            App::logger('countries')
        );
    }

    public function enabled(): bool
    {
        return $this->settings->getBool('country_checks_enabled', true);
    }

    /** @return list<string> */
    public function countries(): array
    {
        return Countries::parse($this->settings->getString('country_list', Countries::DEFAULT));
    }

    /**
     * Test one website from every configured country and store the result.
     *
     * @param array<string, mixed> $website
     * @return array{website_id: int, countries: list<array<string, mixed>>, summary: array<string, int>, tests: int}
     * @throws RateLimitException when the free allowance is used up.
     */
    public function run(array $website): array
    {
        $countries = $this->countries();
        $url = (string) $website['url'];
        $slow = $this->settings->getInt('slow_threshold', 5000);
        $tests = 0;

        // First opinion: one probe per country.
        $first = $this->globalping->results($this->globalping->start($url, $countries));
        $tests += count($first);
        $missing = array_values(array_diff($countries, array_keys($first)));
        if ($missing !== []) {
            try {
                $extra = $this->checkHost->check($url, $missing);
                $first += $extra;
                $tests += count($extra);
            } catch (Throwable $e) {
                $this->logger->notice('check-host.net fallback failed', ['error' => $e->getMessage()]);
            }
        }

        // Second opinion from another network, only where the first probe had a problem and most countries
        // could open the site (otherwise it is an outage and the main monitor deals with it).
        $problems = [];
        foreach ($first as $country => $probe) {
            if (in_array($this->classifier->single($probe, $slow), CountryClassifier::PROBLEMS, true)) {
                $problems[] = $country;
            }
        }
        $ok = count($first) - count($problems);
        $second = [];
        if ($problems !== [] && $ok >= count($first) * 0.4) {
            $viaGlobalping = array_values(array_filter($problems, static fn (string $c): bool => $first[$c]->provider === 'globalping'));
            $viaCheckHost = array_values(array_diff($problems, $viaGlobalping));
            try {
                if ($viaGlobalping !== []) {
                    $second += $this->globalping->results($this->globalping->start($url, $viaGlobalping));
                }
                if ($viaCheckHost !== []) {
                    $second += $this->checkHost->check($url, $viaCheckHost, 1);
                }
                $tests += count($second);
            } catch (RateLimitException $e) {
                // Keep the first results; the problem simply stays unconfirmed until the next run.
                $this->logger->notice('No allowance left for a second opinion', ['website_id' => (int) $website['id']]);
            }
        }

        $results = $this->classifier->classify($first, $second, $countries, $slow);
        $changes = $this->repo->saveRun((int) $website['id'], $results);
        $this->alert($website, $changes);

        return ['website_id' => (int) $website['id'], 'countries' => $this->presentWebsite((int) $website['id']), 'summary' => $this->summarise($results), 'tests' => $tests];
    }

    /**
     * @param array<string, mixed> $website
     * @param array{problems: list<array<string, mixed>>, recovered: list<array<string, mixed>>} $changes
     */
    private function alert(array $website, array $changes): void
    {
        if ($this->notifications === null || ($changes['problems'] === [] && $changes['recovered'] === [])) {
            return;
        }
        try {
            $this->notifications->countryAvailability($website, $changes['problems'], $changes['recovered']);
            $this->repo->markAlerted((int) $website['id'], array_map(static fn (array $r): string => (string) $r['country'], $changes['problems']));
        } catch (Throwable $e) {
            $this->logger->error('Country alert failed', ['website_id' => (int) $website['id'], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Scheduled sweep: check websites that are due, oldest first, until the time or the hourly allowance runs out.
     *
     * @return array{checked: int, tests: int, stopped: ?string}
     */
    public function sweep(int $maxSeconds = 240): array
    {
        if (!$this->enabled()) {
            return ['checked' => 0, 'tests' => 0, 'stopped' => 'Country checks are switched off.'];
        }
        $hours = max(1, $this->settings->getInt('country_check_interval_hours', 24));
        $before = utc_now()->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');
        $started = microtime(true);
        $checked = 0;
        $tests = 0;
        $perRun = count($this->countries()) + 4;
        foreach ($this->repo->due($before, 50) as $website) {
            if (microtime(true) - $started > $maxSeconds) {
                return ['checked' => $checked, 'tests' => $tests, 'stopped' => 'Time limit reached; the rest is checked on the next run.'];
            }
            // Leave room in the hourly allowance for people pressing "Check now".
            if ($this->globalping->remaining !== null && $this->globalping->remaining < $perRun + 20) {
                return ['checked' => $checked, 'tests' => $tests, 'stopped' => 'Hourly allowance nearly used; continuing next hour.'];
            }
            try {
                $result = $this->run($website);
                $checked++;
                $tests += $result['tests'];
            } catch (RateLimitException $e) {
                return ['checked' => $checked, 'tests' => $tests, 'stopped' => $e->getMessage()];
            } catch (Throwable $e) {
                $this->logger->warning('Country check failed', ['website_id' => (int) $website['id'], 'error' => $e->getMessage()]);
            }
        }
        return ['checked' => $checked, 'tests' => $tests, 'stopped' => null];
    }

    /** Remove history older than the retention period (daily cleanup). */
    public function purge(): int
    {
        $days = max(7, $this->settings->getInt('country_retention_days', 90));
        return $this->repo->purgeOlderThan(utc_now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * Data for Performance → Countries.
     *
     * @return array<string, mixed>
     */
    public function pageData(bool $canRun, bool $canSettings): array
    {
        $countries = $this->countries();
        $matrix = $this->repo->matrix();
        $rows = [];
        $counts = ['everywhere' => 0, 'slow' => 0, 'problem' => 0, 'unchecked' => 0];
        foreach ($this->websites->all() as $w) {
            $id = (int) $w['id'];
            $cells = [];
            $worst = null;
            foreach ($countries as $c) {
                $cells[$c] = isset($matrix[$id][$c]) ? $this->presentCell($matrix[$id][$c]) : null;
                $result = $cells[$c]['result'] ?? null;
                if (in_array($result, CountryClassifier::PROBLEMS, true)) {
                    $worst = 'problem';
                } elseif ($result === CountryClassifier::SLOW && $worst === null) {
                    $worst = 'slow';
                }
            }
            $checked = $w['country_checked_at'] ?? null;
            $counts[$checked === null ? 'unchecked' : ($worst === 'problem' ? 'problem' : ($worst === 'slow' ? 'slow' : 'everywhere'))]++;
            $rows[] = [
                'id'          => $id,
                'name'        => (string) $w['name'],
                'domain'      => (string) $w['domain'],
                'client_name' => (string) $w['client_name'],
                'favicon_url' => $w['favicon_url'],
                'paused'      => (int) $w['monitoring_enabled'] !== 1,
                'checked_at'  => $checked,
                'worst'       => $checked === null ? 'unchecked' : ($worst ?? 'ok'),
                'cells'       => $cells,
            ];
        }
        return [
            'enabled'     => $this->enabled(),
            'countries'   => array_map(static fn (string $c): array => ['code' => $c, 'name' => Countries::name($c)], $countries),
            'rows'        => $rows,
            'counts'      => $counts,
            'labels'      => CountryClassifier::LABELS,
            'tones'       => CountryClassifier::TONES,
            'explain'     => CountryClassifier::EXPLANATIONS,
            'interval'    => max(1, $this->settings->getInt('country_check_interval_hours', 24)),
            'hasToken'    => $this->settings->getString('globalping_token') !== '',
            'canRun'      => $canRun,
            'canSettings' => $canSettings,
        ];
    }

    /**
     * Latest result per country for one website (website page strip and the API).
     *
     * @return list<array<string, mixed>>
     */
    public function presentWebsite(int $websiteId): array
    {
        $rows = $this->repo->forWebsite($websiteId);
        $out = [];
        foreach ($this->countries() as $c) {
            $cell = isset($rows[$c]) ? $this->presentCell($rows[$c]) : null;
            $out[] = ['code' => $c, 'name' => Countries::name($c)] + ($cell ?? ['result' => null]);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row country_status row
     * @return array<string, mixed>
     */
    private function presentCell(array $row): array
    {
        return [
            'result'      => (string) $row['result'],
            'status_code' => $row['status_code'] !== null ? (int) $row['status_code'] : null,
            'response_ms' => $row['response_ms'] !== null ? (int) $row['response_ms'] : null,
            'error'       => $row['error'],
            'network'     => $row['network'],
            'city'        => $row['city'],
            'probe_type'  => $row['probe_type'],
            'provider'    => $row['provider'],
            'confirmed'   => (int) $row['confirmed'] === 1,
            'checked_at'  => $row['checked_at'],
            'changed_at'  => $row['changed_at'],
        ];
    }

    /**
     * @param array<string, array{result: string, probe: ?Probe, confirmed: bool}> $results
     * @return array<string, int>
     */
    private function summarise(array $results): array
    {
        $out = array_fill_keys(array_keys(CountryClassifier::LABELS), 0);
        foreach ($results as $r) {
            $out[$r['result']]++;
        }
        return $out;
    }
}
