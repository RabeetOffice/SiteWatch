<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
};

return [
    'host'     => (string) $env('DB_HOST', '127.0.0.1'),
    'port'     => (int) $env('DB_PORT', 3306),
    'name'     => (string) $env('DB_NAME', 'sitewatch'),
    'user'     => (string) $env('DB_USER', 'root'),
    'password' => (string) $env('DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ],
];
