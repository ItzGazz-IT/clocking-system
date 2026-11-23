<?php

declare(strict_types=1);

return [
    'name' => 'Time & Attendance Tracking',
    'environment' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Africa/Johannesburg'),
    'key' => env('APP_KEY'),
    'version' => env('APP_VERSION', '1.0.4'),
    // Keep the published binary, dashboard, download and updater on one release.
    'agent_version' => env('AGENT_VERSION', '1.4.0'),
];
