<?php

declare(strict_types=1);

// Router for PHP's development server. Apache uses public/.htaccess instead.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicFile = __DIR__ . '/public' . $requestPath;

if ($requestPath !== '/' && is_file($publicFile)) {
    return false;
}

require __DIR__ . '/public/index.php';
