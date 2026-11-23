<?php

declare(strict_types=1);

use App\Core\Request;

$application = require dirname(__DIR__) . '/bootstrap/app.php';
$application->handle(Request::capture())->send();

