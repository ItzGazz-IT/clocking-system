<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AttendanceService;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

App\Core\Environment::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config');
date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

$tests = [];
$tests['request strips configured base path'] = static function (): void {
    $request = new Request('GET', '/dashboard');
    if ($request->path() !== '/dashboard') {
        throw new RuntimeException('Unexpected request path.');
    }
};
$tests['request accepts index.php hosting paths'] = static function (): void {
    $request = new Request('POST', '/index.php/shifts/2/edit');
    if ($request->path() !== '/shifts/2/edit') {
        throw new RuntimeException('index.php was not removed from the request path.');
    }
};
$tests['department validation accepts a valid form'] = static function (): void {
    if (Validator::department(['code' => 'OPS', 'name' => 'Operations']) !== []) {
        throw new RuntimeException('Valid department was rejected.');
    }
};
$tests['shift validation rejects invalid minutes'] = static function (): void {
    $errors = Validator::shift([
        'code' => 'DAY', 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '17:00',
        'paid_minutes' => 2000, 'grace_period_minutes' => 5, 'expected_lunch_minutes' => 60,
    ]);
    if (!isset($errors['paid_minutes'])) {
        throw new RuntimeException('Invalid paid minutes were accepted.');
    }
};
$tests['after-hours clock-out warning accepts the active request'] = static function (): void {
    $database = Database::connection();
    $database->beginTransaction();
    try {
        $method = new ReflectionMethod(AttendanceService::class, 'enforceScheduleConstraint');
        $method->invoke(
            new AttendanceService(),
            [
                'id' => 1,
                'shift_id' => 1,
                'timezone' => 'Africa/Johannesburg',
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'grace_period_minutes' => 5,
                'spans_midnight' => 0,
            ],
            new DateTimeImmutable('2026-07-15 18:00:00', new DateTimeZone('Africa/Johannesburg')),
            'clock_out',
            new Request('POST', '/api/clockout'),
            null
        );
    } finally {
        if ($database->inTransaction()) $database->rollBack();
    }
};
$tests['password policy requires complexity'] = static function (): void {
    $errors = Validator::passwordChange([
        'current_password' => 'current',
        'password' => 'too-short',
        'password_confirmation' => 'too-short',
    ]);
    if (!isset($errors['password'])) {
        throw new RuntimeException('Weak password was accepted.');
    }
};
$tests['database contains configured transition policy'] = static function (): void {
    $count = (int) Database::connection()->query(
        'SELECT COUNT(*) FROM attendance_event_transitions WHERE is_active = 1'
    )->fetchColumn();
    if ($count < 12) {
        throw new RuntimeException('Attendance transition policy is incomplete.');
    }
};
$tests['administrator has Phase 2 permissions'] = static function (): void {
    $statement = Database::connection()->prepare(
        'SELECT COUNT(*) FROM role_permissions rp
         JOIN roles r ON r.id = rp.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE r.slug = "administrator"
           AND p.slug IN ("departments.manage", "shifts.manage", "employees.manage", "attendance.record")'
    );
    $statement->execute();
    if ((int) $statement->fetchColumn() !== 4) {
        throw new RuntimeException('Administrator permissions are incomplete.');
    }
};
$tests['Phase 3 migration is applied'] = static function (): void {
    $statement = Database::connection()->prepare(
        'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
    );
    $statement->execute(['migration' => '20260706_002_phase3']);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Phase 3 migration is not recorded.');
    }
};
$tests['Phase 3 permissions are assigned'] = static function (): void {
    $statement = Database::connection()->query(
        'SELECT COUNT(*) FROM permissions
         WHERE slug IN ("users.manage", "attendance.correct", "attendance.history.view")'
    );
    if ((int) $statement->fetchColumn() !== 3) {
        throw new RuntimeException('Phase 3 permissions are incomplete.');
    }
};
$tests['employee role can view personal attendance history'] = static function (): void {
    $statement = Database::connection()->query(
        'SELECT COUNT(*) FROM role_permissions rp
         JOIN roles r ON r.id = rp.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE r.slug = "employee" AND p.slug = "attendance.history.view"'
    );
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Employee history permission is missing.');
    }
};
$tests['production hardening migration is applied'] = static function (): void {
    $statement = Database::connection()->prepare(
        'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
    );
    $statement->execute(['migration' => '20260706_004_production_hardening']);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Production hardening migration is not recorded.');
    }
};
$tests['attendance supports multiple daily sessions'] = static function (): void {
    $statement = Database::connection()->query(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = "attendance"
           AND index_name = "uq_attendance_employee_date_session"'
    );
    if ((int) $statement->fetchColumn() !== 3) {
        throw new RuntimeException('Attendance session uniqueness index is incomplete.');
    }
};
$tests['audit sanitizer redacts credential keys'] = static function (): void {
    $reflection = new ReflectionMethod(App\Services\AuditService::class, 'sanitize');
    $reflection->setAccessible(true);
    $result = $reflection->invoke(new App\Services\AuditService(), [
        '_token' => 'token-value',
        'password' => 'password-value',
        'safe' => 'visible',
    ]);
    if ($result['_token'] !== '[REDACTED]'
        || $result['password'] !== '[REDACTED]'
        || $result['safe'] !== 'visible') {
        throw new RuntimeException('Audit sanitizer did not redact credentials.');
    }
};
$tests['productivity insights migration is applied'] = static function (): void {
    $statement = Database::connection()->prepare(
        'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
    );
    $statement->execute(['migration' => '20260713_001_productivity_insights']);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Productivity insights migration is not recorded.');
    }
};
$tests['productivity classification defaults are available'] = static function (): void {
    $statement = Database::connection()->query(
        'SELECT COUNT(*) FROM productivity_classification_rules
         WHERE category IN ("productive", "neutral", "distracting")'
    );
    if ((int) $statement->fetchColumn() < 3) {
        throw new RuntimeException('Productivity classification defaults are incomplete.');
    }
};
$tests['dashboard late details match the late metric'] = static function (): void {
    $service = new App\Services\DashboardService();
    $metrics = $service->metrics(1);
    $lateEmployees = $service->lateEmployees(1);
    if ((int) $metrics['late'] !== count($lateEmployees)) {
        throw new RuntimeException('Dashboard late count does not match its detail records.');
    }
};
$tests['employee activity report is company scoped'] = static function (): void {
    $employeeId = Database::connection()->query(
        'SELECT id FROM employees WHERE company_id = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1'
    )->fetchColumn();
    if ($employeeId === false) {
        throw new RuntimeException('No employee is available for activity report verification.');
    }
    $report = (new App\Services\EmployeeActivityReportService())->report(1, (int) $employeeId, date('Y-m-d'), date('Y-m-d'));
    if ($report === null || (int) $report['employee']['id'] !== (int) $employeeId) {
        throw new RuntimeException('Employee activity report scope is invalid.');
    }
};
$tests['employee activity PDF is a valid styled document'] = static function (): void {
    $employeeId = Database::connection()->query(
        'SELECT id FROM employees WHERE company_id = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1'
    )->fetchColumn();
    $report = $employeeId === false ? null : (new App\Services\EmployeeActivityReportService())
        ->report(1, (int) $employeeId, date('Y-m-d'), date('Y-m-d'));
    if ($report === null) {
        throw new RuntimeException('No employee report is available for PDF verification.');
    }
    $pdf = (new App\Services\EmployeeActivityPdfService())->render($report);
    if (!str_starts_with($pdf, '%PDF-1.4')
        || !str_contains($pdf, '/Type /Pages')
        || !str_contains($pdf, 'Employee activity report')) {
        throw new RuntimeException('Employee activity PDF structure is invalid.');
    }
};
$tests['voided attendance events do not block the next transition'] = static function (): void {
    $attendanceId = Database::connection()->query(
        'SELECT ae.attendance_id
         FROM attendance_events ae
         JOIN attendance_event_types et ON et.id = ae.event_type_id
         WHERE et.code = "correction" AND JSON_UNQUOTE(JSON_EXTRACT(ae.metadata, "$.corrected_event")) = "void"
         ORDER BY ae.id DESC LIMIT 1'
    )->fetchColumn();
    if ($attendanceId === false) return;
    $method = new ReflectionMethod(App\Services\AttendanceService::class, 'lastEvent');
    $method->setAccessible(true);
    $event = $method->invoke(new App\Services\AttendanceService(), (int) $attendanceId);
    if ($event !== null && in_array($event['code'], ['correction', 'void'], true)) {
        throw new RuntimeException('A correction was incorrectly treated as the current attendance state.');
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS  {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL  {$name}: {$exception->getMessage()}\n";
    }
}

echo sprintf("\n%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
