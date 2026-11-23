<?php

declare(strict_types=1);

use App\Controllers\Api\AttendanceController;
use App\Controllers\Api\ActivityController;
use App\Controllers\Api\AgentUpdateController;
use App\Controllers\Api\DeviceEnrollmentController;
use App\Controllers\Api\EmployeeController;
use App\Core\Router;

return static function (Router $router): void {
    $protected = ['api.auth', 'api.throttle'];

    $router->post('/api/device/enroll', [DeviceEnrollmentController::class, 'enroll'], ['api.throttle']);
    $router->post('/api/clockin', [AttendanceController::class, 'clockIn'], $protected);
    $router->post('/api/clockout', [AttendanceController::class, 'clockOut'], $protected);
    $router->post('/api/break/start', [AttendanceController::class, 'startBreak'], $protected);
    $router->post('/api/break/end', [AttendanceController::class, 'endBreak'], $protected);
    $router->post('/api/lunch/start', [AttendanceController::class, 'startLunch'], $protected);
    $router->post('/api/lunch/end', [AttendanceController::class, 'endLunch'], $protected);
    $router->post('/api/overtime/start', [AttendanceController::class, 'startOvertime'], $protected);
    $router->post('/api/overtime/end', [AttendanceController::class, 'endOvertime'], $protected);
    $router->post('/api/windows-event', [AttendanceController::class, 'windowsEvent'], $protected);
    $router->post('/api/activity', [ActivityController::class, 'store'], $protected);
    $router->get('/api/agent-update', [AgentUpdateController::class, 'show'], $protected);
    $router->get('/api/attendance', [AttendanceController::class, 'index'], $protected);
    $router->get('/api/employee', [EmployeeController::class, 'show'], $protected);
};
