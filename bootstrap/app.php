<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Environment;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

Environment::load(BASE_PATH . '/.env');
date_default_timezone_set((string) env('APP_TIMEZONE', 'Africa/Johannesburg'));

$application = new Application(BASE_PATH);
$application->bootstrap();

return $application;
