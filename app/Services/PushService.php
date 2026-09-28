<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Permission;
use App\Notifications\AlertMessage;
use App\Repositories\PushSubscriptionRepository;
use App\Repositories\SettingsRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Desktop notifications through Web Push: the browser's push service delivers them even when SiteWatch is closed.
 *
 * The VAPID key pair identifies this SiteWatch to the push services. It is created on first use and the private
 * key is stored encrypted like the other secrets.
 */
final class PushService
{
    /** Events a device can choose; the key is what the browser stores, the value the label shown to people. */
    public const EVENTS = [
        'down'      => 'Websites going down',
        'recovery'  => 'Websites coming back',
        'ssl'       => 'SSL certificates expiring',
        'wordpress' => 'WordPress errors and security events',
        'country'   => 'Country availability problems',
    ];

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function create(): self
    {
        return new self(new PushSubscriptionRepository(App::db()), App::settings(), App::logger('notifications'));
    }

    public static function available(): bool
    {
        return class_exists(WebPush::class);
    }

    /**
     * The public key browsers need to subscribe; creates the key pair the first time.
     */
    public function publicKey(): string
    {
        $public = $this->settings->getString('push_vapid_public');
        if ($public !== '' && $this->settings->getString('push_vapid_private') !== '') {
            return $public;
        }
        if (!self::available()) {
            throw new RuntimeException('Desktop notifications need the minishlink/web-push package: run composer install on the server.');
        }
        self::prepareOpenSsl();
        $keys = VAPID::createVapidKeys();
        $this->settings->setMany(['push_vapid_public' => $keys['publicKey'], 'push_vapid_private' => $keys['privateKey']]);
        $this->settings->reload();
        return $keys['publicKey'];
    }

    public function hasSubscribers(): bool
    {
        return $this->subscriptions->count() > 0;
    }

    /**
     * Send an alert to every device whose owner may see it and who chose that kind of event.
     *
     * @return array{sent: int, failed: int, removed: int}
     */
    public function sendAlert(AlertMessage $message): array
    {
        $event = self::eventKey($message->event);
        $payload = [
            'title' => $message->subject,
            'body'  => self::bodyFor($message),
            'tag'   => $message->event . '-' . ($message->websiteId ?? 0) . ($message->incidentId !== null ? '-' . $message->incidentId : ''),
            'url'   => $this->urlFor($message),
            'sticky' => $message->event === AlertMessage::EVENT_DOWN,
            'time'  => utc_now()->format('c'),
        ];
        $targets = array_filter($this->subscriptions->allActive(), static function (array $s) use ($event): bool {
            $permissions = Permission::decode($s['role_permissions'] ?? null);
            if ($event !== 'test' && !Permission::allows($permissions, 'incidents.view')) {
                return false;
            }
            $wanted = $s['events'] !== null ? json_decode((string) $s['events'], true) : null;
            return $event === 'test' || !is_array($wanted) || in_array($event, $wanted, true);
        });
        return $this->deliver(array_values($targets), $payload);
    }

    /**
     * A test notification to the devices of one user.
     *
     * @return array{sent: int, failed: int, removed: int}
     */
    public function sendTest(int $userId): array
    {
        $appName = $this->settings->getString('app_name', 'SiteWatch') ?: 'SiteWatch';
        return $this->deliver($this->subscriptions->forUser($userId), [
            'title' => $appName . ' test notification',
            'body'  => 'Desktop notifications work on this device.',
            'tag'   => 'test',
            'url'   => base_url('admin/profile.php#device'),
            'time'  => utc_now()->format('c'),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @param array<string, mixed>       $payload
     * @return array{sent: int, failed: int, removed: int}
     */
    private function deliver(array $targets, array $payload): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        if ($targets === []) {
            return $result;
        }
        self::prepareOpenSsl();
        $subject = $this->settings->getString('app_url') !== '' ? $this->settings->getString('app_url') : 'mailto:alerts@sitewatch.invalid';
        $push = new WebPush([
            'VAPID' => [
                'subject'    => str_starts_with($subject, 'http') || str_starts_with($subject, 'mailto:') ? $subject : 'mailto:alerts@sitewatch.invalid',
                'publicKey'  => $this->publicKey(),
                'privateKey' => $this->settings->getString('push_vapid_private'),
            ],
        ], ['TTL' => 3600, 'urgency' => 'high'], 15);
        $push->setReuseVAPIDHeaders(true);

        $byEndpoint = [];
        $json = (string) json_encode($payload + ['open_incidents' => $this->openIncidents()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($targets as $s) {
            $byEndpoint[(string) $s['endpoint']] = (int) $s['id'];
            $push->queueNotification(Subscription::create([
                'endpoint'        => (string) $s['endpoint'],
                'publicKey'       => (string) $s['p256dh'],
                'authToken'       => (string) $s['auth'],
                'contentEncoding' => (string) ($s['encoding'] ?: 'aes128gcm'),
            ]), $json);
        }
        foreach ($push->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            $id = $byEndpoint[$endpoint] ?? null;
            if ($report->isSuccess()) {
                $result['sent']++;
                if ($id !== null) {
                    $this->subscriptions->markUsed($id);
                }
                continue;
            }
            $result['failed']++;
            if ($id !== null) {
                if ($report->isSubscriptionExpired()) {
                    // The browser dropped the subscription (404/410): forget it.
                    $this->subscriptions->delete($id);
                    $result['removed']++;
                } else {
                    $this->subscriptions->markError($id, $report->getReason());
                }
            }
            $this->logger->notice('Push delivery failed', ['reason' => $report->getReason(), 'expired' => $report->isSubscriptionExpired()]);
        }
        return $result;
    }

    private function openIncidents(): ?int
    {
        try {
            return ServiceFactory::incidents()->countOpen();
        } catch (Throwable) {
            return null;
        }
    }

    private function urlFor(AlertMessage $message): string
    {
        if ($message->websiteId === null) {
            return base_url('admin/dashboard.php');
        }
        $tab = match ($message->event) {
            AlertMessage::EVENT_WP_ERROR, AlertMessage::EVENT_WP_SECURITY, AlertMessage::EVENT_WP_VULNERABILITY, AlertMessage::EVENT_WP_AUTOFIX => '&tab=wordpress',
            default => '',
        };
        if ($message->event === AlertMessage::EVENT_COUNTRY) {
            return base_url('admin/performance.php?tab=countries');
        }
        return base_url('admin/website-details.php?id=' . $message->websiteId . $tab);
    }

    public static function eventKey(string $event): string
    {
        return match ($event) {
            AlertMessage::EVENT_DOWN => 'down',
            AlertMessage::EVENT_RECOVERY => 'recovery',
            AlertMessage::EVENT_SSL => 'ssl',
            AlertMessage::EVENT_WP_ERROR, AlertMessage::EVENT_WP_SECURITY, AlertMessage::EVENT_WP_VULNERABILITY, AlertMessage::EVENT_WP_AUTOFIX => 'wordpress',
            AlertMessage::EVENT_COUNTRY => 'country',
            default => 'test',
        };
    }

    /** Two short lines: notifications are glanced at, the details are one click away. */
    private static function bodyFor(AlertMessage $message): string
    {
        $lines = preg_split('/\R/', trim($message->text)) ?: [];
        $lines = array_values(array_filter(array_map('trim', array_slice($lines, 1)), static fn (string $l): bool => $l !== ''));
        $picked = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'Open website details') || str_starts_with($line, 'http')) {
                continue;
            }
            $picked[] = preg_replace('/\s{2,}/', ' ', $line) ?? $line;
            if (count($picked) === 3) {
                break;
            }
        }
        return mb_substr(implode("\n", $picked), 0, 300);
    }

    /**
     * Windows builds of PHP (XAMPP) cannot create EC keys unless OpenSSL finds its configuration file.
     */
    public static function prepareOpenSsl(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || getenv('OPENSSL_CONF')) {
            return;
        }
        $ini = php_ini_loaded_file();
        $candidates = array_filter([
            $ini ? dirname($ini) . '/extras/ssl/openssl.cnf' : null,
            PHP_BINARY !== '' ? dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf' : null,
            'C:/xampp/php/extras/ssl/openssl.cnf',
            'C:/xampp/apache/conf/openssl.cnf',
        ]);
        foreach ($candidates as $file) {
            if (is_file($file)) {
                putenv('OPENSSL_CONF=' . $file);
                return;
            }
        }
    }
}
