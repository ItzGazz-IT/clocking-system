<?php
declare(strict_types=1);

// Run every minute, including when no employee PC is currently online.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
App\Core\Environment::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');
$lock = fopen(BASE_PATH . '/logs/flexible-refresh.lock', 'c');
if ($lock === false) { fwrite(STDERR, "Cannot open flexible attendance refresh lock.\n"); exit(1); }
if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); exit(0); }
$started = microtime(true);
try {
    $days = (new App\Services\FlexibleAttendanceService())->refreshOpenDays();
    echo json_encode(['at'=>gmdate(DATE_ATOM),'event'=>'flexible_refresh','days'=>$days,
        'elapsed_seconds'=>round(microtime(true)-$started,3)], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['at'=>gmdate(DATE_ATOM),'event'=>'flexible_refresh_failed','error'=>$error::class]) . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN); fclose($lock);
}
