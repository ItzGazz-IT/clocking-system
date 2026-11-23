<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\DashboardService;
use App\Services\EmployeeAccessService;
use App\Services\SetupChecklistService;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $dashboard = new DashboardService();
        $employees = (new EmployeeAccessService())->accessibleEmployees($user);
        $scope = $user->can('attendance.view.all') ? null : array_column($employees, 'id');
        $statusUnavailable = false;
        try {
            $employeeStatusCards = $dashboard->employeeStatusCards($user->companyId, $scope);
        } catch (\Throwable $exception) {
            $employeeStatusCards = [];
            $statusUnavailable = true;
            error_log('Dashboard employee status cards failed: ' . $exception->getMessage());
        }
        $metrics = $dashboard->automaticMetrics($employeeStatusCards);
        $activityUnavailable = false;
        try {
            $activity = $dashboard->recentAgentActivity($user->companyId, 12, $scope);
        } catch (\Throwable $exception) {
            $activity = [];
            $activityUnavailable = true;
            error_log('Dashboard agent activity failed: ' . $exception->getMessage());
        }

        return $this->view('dashboard/index', [
            'user' => $user,
            'metrics' => $metrics,
            'activity' => $activity,
            'activityUnavailable' => $activityUnavailable,
            'statusUnavailable' => $statusUnavailable,
            'employeeStatusCards' => $employeeStatusCards,
            'employees' => $employees,
            'setupChecklist' => (new SetupChecklistService())->items($user->companyId),
            'appVersion' => (string) config('app.version', '1.0.1'),
            'agentVersion' => (string) config('app.agent_version', '1.0.1'),
            'selfService' => $user->employeeId !== null
                && !$user->can('attendance.view.all')
                && !$user->can('attendance.view.department'),
            'flash' => Session::pull('_flash'),
        ]);
    }
}
