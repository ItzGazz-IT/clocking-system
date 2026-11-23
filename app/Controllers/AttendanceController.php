<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AttendanceService;
use App\Services\EmployeeAccessService;

final class AttendanceController extends Controller
{
    private const ACTIONS = [
        'clock-in' => 'clock_in',
        'clock-out' => 'clock_out',
        'break-start' => 'break_start',
        'break-end' => 'break_end',
        'lunch-start' => 'lunch_start',
        'lunch-end' => 'lunch_end',
        'overtime-start' => 'clock_in',
        'overtime-end' => 'clock_out',
    ];

    public function record(Request $request, string $action): Response
    {
        $eventCode = self::ACTIONS[$action] ?? throw new HttpException(404, 'Unknown attendance action.');
        $employeeId = filter_var($request->input('employee_id'), FILTER_VALIDATE_INT);
        if ($employeeId === false) {
            Session::flash('error', 'Choose an employee before recording attendance.');
            return Response::redirect(app_url('/dashboard'));
        }
        $user = Auth::user();
        if (!(new EmployeeAccessService())->canAccess($user, (int) $employeeId)) {
            Session::flash('error', 'You do not have access to that employee.');
            return Response::redirect(app_url('/dashboard'));
        }
        try {
            (new AttendanceService())->record(
                $user->companyId,
                (int) $employeeId,
                $eventCode,
                $request,
                $user->id,
                null,
                (string) $request->input('idempotency_key', ''),
                str_starts_with($action, 'overtime-') ? 'overtime' : 'regular'
            );
            Session::flash('success', str_replace('_', ' ', ucfirst($eventCode)) . ' recorded.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect(app_url('/dashboard'));
    }
}
