<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Environment;
use App\Services\AnomalyDetectionService;
use App\Services\AttendanceService;
use App\Services\FlexibleAttendanceService;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

Environment::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config');

$database = Database::connection();
$operations = [
    'expired API rate limits' => 'DELETE FROM api_rate_limits WHERE expires_at < CURRENT_TIMESTAMP',
    'old login attempts' => 'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)',
    'expired password reset records' => 'DELETE FROM password_reset_tokens
        WHERE expires_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)
           OR used_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)',
];

foreach ($operations as $label => $sql) {
    $count = $database->exec($sql);
    echo sprintf("Removed %d %s.\n", $count, $label);
}

// Run anomaly detection for all companies
echo "\nRunning anomaly detection scans...\n";
$companiesStmt = $database->prepare('SELECT id FROM companies WHERE is_active = TRUE AND deleted_at IS NULL');
$companiesStmt->execute();
$companies = $companiesStmt->fetchAll(\PDO::FETCH_COLUMN);

$attendanceService = new AttendanceService();
$employees = $database->query(
    'SELECT e.company_id, e.id FROM employees e
     WHERE e.employment_status = "active" AND e.deleted_at IS NULL'
)->fetchAll(\PDO::FETCH_ASSOC);
$autoClockOuts = 0;
foreach ($employees as $employee) {
    $autoClockOuts += $attendanceService->autoClockOutOverdueSessions(
        (int) $employee['company_id'],
        (int) $employee['id']
    );
}
echo sprintf("Automatically closed %d overdue sessions at scheduled shift end.\n", $autoClockOuts);

$flexibleDays = (new FlexibleAttendanceService())->refreshOpenDays();
echo sprintf("Recalculated %d flexible attendance days.\n", $flexibleDays);

$anomalyService = new AnomalyDetectionService();
foreach ($companies as $companyId) {
    $results = $anomalyService->scanCompany((int) $companyId);
    $totalAnomalies = array_sum($results);
    if ($totalAnomalies > 0) {
        echo sprintf(
            "Company %d: Found %d anomalies (Idle: %d, Timestamp: %d, Multi-device: %d, Schedule: %d, Pattern: %d, Network: %d, Agent offline: %d, Lifecycle: %d)\n",
            $companyId,
            $totalAnomalies,
            $results['idle_time_anomalies'],
            $results['timestamp_integrity'],
            $results['simultaneous_devices'],
            $results['schedule_violations'],
            $results['pattern_anomalies'],
            $results['network_anomalies'],
            $results['agent_offline'],
            $results['agent_lifecycle']
        );
    }
}

echo "Maintenance complete.\n";
