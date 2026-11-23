<?php

declare(strict_types=1);

return [
    'name' => env('SESSION_NAME', 'attendance_session'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'secure' => filter_var(env('SESSION_SECURE', true), FILTER_VALIDATE_BOOL),
    'http_only' => true,
    'same_site' => env('SESSION_SAME_SITE', 'Lax'),
    'regenerate_interval' => 900,
];
