<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Monitoring\MonitoringScheduler;
use App\Monitoring\MonitorManager;
use App\Monitoring\SsrfGuard;
use App\Monitoring\UptimeCalculator;
use App\Repositories\ActivityRepository;
use App\Repositories\CheckRepository;
use App\Repositories\DailyStatsRepository;
use App\Repositories\HeartbeatRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Repositories\WebsiteRepository;

/**
 * Small factory so pages and API endpoints do not repeat dependency wiring.
 */
final class ServiceFactory
{
    public static function websites(): WebsiteRepository
    {
        return new WebsiteRepository(App::db());
    }

    public static function checks(): CheckRepository
    {
        return new CheckRepository(App::db());
    }

    public static function incidents(): IncidentRepository
    {
        return new IncidentRepository(App::db());
    }

    public static function dailyStats(): DailyStatsRepository
    {
        return new DailyStatsRepository(App::db());
    }

    public static function activity(): ActivityRepository
    {
        return new ActivityRepository(App::db());
    }

    public static function notifications(): NotificationRepository
    {
        return new NotificationRepository(App::db());
    }

    public static function heartbeats(): HeartbeatRepository
    {
        return new HeartbeatRepository(App::db());
    }

    public static function users(): UserRepository
    {
        return new UserRepository(App::db());
    }

    public static function scheduler(): MonitoringScheduler
    {
        return new MonitoringScheduler(self::websites(), self::heartbeats(), App::settings());
    }

    public static function uptime(): UptimeCalculator
    {
        return new UptimeCalculator(self::checks(), self::dailyStats());
    }

    public static function websiteService(): WebsiteService
    {
        return new WebsiteService(
            self::websites(),
            self::activity(),
            App::settings(),
            new SsrfGuard((bool) App::config()->get('app.monitor.allow_private_targets', false))
        );
    }

    public static function dashboard(): DashboardService
    {
        return new DashboardService(self::websites(), self::incidents(), self::checks(), self::dailyStats(), self::activity(), self::scheduler(), self::uptime());
    }

    public static function reports(): ReportService
    {
        return new ReportService(self::websites(), self::dailyStats(), self::incidents());
    }

    public static function monitor(): MonitorManager
    {
        return MonitorManager::create();
    }
}
