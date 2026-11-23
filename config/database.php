<?php

declare(strict_types=1);

$localPath = __DIR__ . '/database.local.json';
$local = is_file($localPath) ? json_decode((string) file_get_contents($localPath), true, 512, JSON_THROW_ON_ERROR) : [], $local);

return array_replace([
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', ''),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'ssl_ca' => env('DB_SSL_CA'),
    'ssl_verify' => filter_var(env('DB_SSL_VERIFY', true), FILTER_VALIDATE_BOOL),
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ],
], $local);
