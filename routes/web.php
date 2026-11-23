<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AccountController;
use App\Controllers\AttendanceController;
use App\Controllers\DashboardController;
use App\Controllers\DepartmentController;
use App\Controllers\DownloadController;
use App\Controllers\EmployeeController;
use App\Controllers\FaviconController;
use App\Controllers\ForgotPasswordController;
use App\Controllers\ReportController;
use App\Controllers\ShiftController;
use App\Controllers\SettingsController;
use App\Controllers\SetupController;
use App\Controllers\DatabaseSetupController;
use App\Controllers\UserController;
use App\Controllers\AttendanceHistoryController;
use App\Controllers\AnomalyAlertsController;
use App\Controllers\ActivityReportController;
use App\Controllers\DeviceController;
use App\Controllers\WindowsActivityController;
use App\Controllers\EmployeeActivityReportController;
use App\Controllers\OvertimeApprovalController;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router): void {
    $router->get('/', fn (): Response => Response::redirect((string) config('app.url') . '/dashboard'));
    $router->get('/favicon.svg', [FaviconController::class, 'svg']);
    $router->get('/favicon.ico', [FaviconController::class, 'svg']);

    $router->get('/setup/database', [DatabaseSetupController::class, 'show']);
    $router->post('/setup/database', [DatabaseSetupController::class, 'store'], ['csrf']);
    $router->get('/setup', [SetupController::class, 'show'], ['guest']);
    $router->post('/setup', [SetupController::class, 'store'], ['guest', 'csrf']);

    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
    $router->get('/forgot-password', [ForgotPasswordController::class, 'show'], ['guest']);
    $router->post('/forgot-password', [ForgotPasswordController::class, 'store'], ['guest', 'csrf']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

    $router->get('/dashboard', [DashboardController::class, 'index'], [
        'auth',
        'permission:dashboard.view',
    ]);


    $router->get('/windows-app/download', [DownloadController::class, 'clockingAgent'], [
        'auth',
    ]);

    $router->get('/account/password', [AccountController::class, 'password'], [
        'auth',
    ]);
    $router->post('/account/password', [AccountController::class, 'updatePassword'], [
        'auth', 'csrf',
    ]);

    $router->get('/departments', [DepartmentController::class, 'index'], [
        'auth', 'permission:departments.view',
    ]);
    $router->post('/departments', [DepartmentController::class, 'store'], [
        'auth', 'permission:departments.manage', 'csrf',
    ]);
    $router->get('/departments/{id}/edit', [DepartmentController::class, 'edit'], [
        'auth', 'permission:departments.manage',
    ]);
    $router->post('/departments/{id}/edit', [DepartmentController::class, 'update'], [
        'auth', 'permission:departments.manage', 'csrf',
    ]);
    $router->post('/departments/{id}/archive', [DepartmentController::class, 'archive'], [
        'auth', 'permission:departments.manage', 'csrf',
    ]);

    $router->get('/shifts', [ShiftController::class, 'index'], [
        'auth', 'permission:shifts.view',
    ]);
    $router->post('/shifts', [ShiftController::class, 'store'], [
        'auth', 'permission:shifts.manage', 'csrf',
    ]);
    $router->get('/shifts/{id}/edit', [ShiftController::class, 'edit'], [
        'auth', 'permission:shifts.manage',
    ]);
    $router->post('/shifts/{id}/edit', [ShiftController::class, 'update'], [
        'auth', 'permission:shifts.manage', 'csrf',
    ]);
    $router->post('/shifts/{id}/archive', [ShiftController::class, 'archive'], [
        'auth', 'permission:shifts.manage', 'csrf',
    ]);

    $router->get('/employees', [EmployeeController::class, 'index'], [
        'auth', 'permission:employees.view',
    ]);
    $router->post('/employees', [EmployeeController::class, 'store'], [
        'auth', 'permission:employees.manage', 'csrf',
    ]);
    $router->get('/employees/{id}/edit', [EmployeeController::class, 'edit'], [
        'auth', 'permission:employees.manage',
    ]);
    $router->post('/employees/{id}/edit', [EmployeeController::class, 'update'], [
        'auth', 'permission:employees.manage', 'csrf',
    ]);
    $router->post('/employees/{id}/archive', [EmployeeController::class, 'archive'], [
        'auth', 'permission:employees.manage', 'csrf',
    ]);

    $router->post('/attendance/{action}', [AttendanceController::class, 'record'], [
        'auth', 'permission:attendance.record', 'csrf',
    ]);

    $router->get('/settings', [SettingsController::class, 'index'], [
        'auth', 'permission:settings.manage',
    ]);
    $router->post('/settings', [SettingsController::class, 'update'], [
        'auth', 'permission:settings.manage', 'csrf',
    ]);

    $router->get('/users', [UserController::class, 'index'], [
        'auth', 'permission:users.manage',
    ]);
    $router->post('/users', [UserController::class, 'store'], [
        'auth', 'permission:users.manage', 'csrf',
    ]);
    $router->post('/users/{id}/reset-password', [UserController::class, 'resetPassword'], [
        'auth', 'permission:users.manage', 'csrf',
    ]);
    $router->post('/users/{id}/toggle', [UserController::class, 'toggle'], [
        'auth', 'permission:users.manage', 'csrf',
    ]);
    $router->post('/users/{id}/role', [UserController::class, 'updateRole'], [
        'auth', 'permission:users.manage', 'csrf',
    ]);

    $router->get('/attendance/history', [AttendanceHistoryController::class, 'index'], [
        'auth', 'permission:attendance.history.view',
    ]);
    $router->post('/attendance/events/{id}/correct', [AttendanceHistoryController::class, 'correct'], [
        'auth', 'permission:attendance.correct', 'csrf',
    ]);
    $router->post('/attendance/{id}/events/add', [AttendanceHistoryController::class, 'addMissing'], [
        'auth', 'permission:attendance.correct', 'csrf',
    ]);
    $router->get('/overtime-approvals', [OvertimeApprovalController::class, 'index'], [
        'auth', 'permission:attendance.correct',
    ]);
    $router->post('/overtime-approvals/{id}', [OvertimeApprovalController::class, 'review'], [
        'auth', 'permission:attendance.correct', 'csrf',
    ]);

    $router->get('/alerts', [AnomalyAlertsController::class, 'index'], [
        'auth', 'permission:attendance.view.all',
    ]);
    $router->get('/alerts/{id}', [AnomalyAlertsController::class, 'show'], [
        'auth', 'permission:attendance.view.all',
    ]);
    $router->post('/alerts/{id}/review', [AnomalyAlertsController::class, 'review'], [
        'auth', 'permission:attendance.view.all', 'csrf',
    ]);

    $router->get('/devices', [DeviceController::class, 'index'], [
    'auth', 'permission:settings.manage',
]);

$router->post('/devices', [DeviceController::class, 'store'], [
    'auth', 'permission:settings.manage', 'csrf',
]);

    $router->get('/windows-activity', [WindowsActivityController::class, 'index'], [
    'auth', 'permission:attendance.view.all',
]);

    $router->get('/reports/weekly', [ReportController::class, 'weekly'], [
        'auth', 'permission:attendance.view.all',
    ]);
    $router->get('/reports/weekly.pdf', [ReportController::class, 'weeklyPdf'], [
        'auth', 'permission:attendance.view.all',
    ]);
    $router->get('/reports/activity', [ActivityReportController::class, 'index'], [
        'auth', 'permission:attendance.view.all',
    ]);
    $router->get('/reports/activity.pdf', [ActivityReportController::class, 'productivityPdf'], [
        'auth', 'permission:attendance.view.all',
    ]);
    $router->get('/reports/employees/{id}/activity', [EmployeeActivityReportController::class, 'show'], [
        'auth', 'permission:attendance.view.all',
    ]);
    $router->get('/reports/employees/{id}/activity.pdf', [EmployeeActivityReportController::class, 'pdf'], [
        'auth', 'permission:attendance.view.all',
    ]);
    $router->post('/reports/activity/classifications', [ActivityReportController::class, 'storeClassification'], [
        'auth', 'permission:settings.manage', 'csrf',
    ]);
    $router->post('/reports/activity/classifications/{id}/delete', [ActivityReportController::class, 'deleteClassification'], [
        'auth', 'permission:settings.manage', 'csrf',
    ]);
    $router->get('/my/productivity', [ActivityReportController::class, 'mine'], [
        'auth', 'permission:dashboard.view',
    ]);

};
