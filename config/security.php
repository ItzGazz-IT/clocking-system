<?php

declare(strict_types=1);

return [
    'password' => [
        'algorithm' => PASSWORD_DEFAULT,
        'options' => [],
    ],
    'login' => [
        'max_attempts' => 5,
        'decay_minutes' => 15,
    ],
    'csrf' => [
        'token_name' => '_token',
        'session_key' => '_csrf_token',
    ],
    'api_rate_limit' => [
        'attempts' => (int) env('API_RATE_LIMIT_ATTEMPTS', 120),
        'window_seconds' => (int) env('API_RATE_LIMIT_WINDOW', 60),
    ],
];
