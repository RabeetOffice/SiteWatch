<?php

declare(strict_types=1);

/**
 * Test bootstrap: boots the application container without touching the database.
 */

use App\Core\App;
use App\Core\Config;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}
date_default_timezone_set('UTC');

if (!App::isBooted()) {
    App::boot(new Config(dirname(__DIR__) . '/config'));
}
