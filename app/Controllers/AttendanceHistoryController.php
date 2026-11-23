<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AttendanceCorrectionService;
use App\Services\AttendanceService;
use App\Services\CatalogService;
use App\Services\EmployeeAccessService;

final class AttendanceHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $access = new EmployeeAccessService();
        $employees = $access->accessibleEmployees($user);
        $requested = filter_var($request->input('employee_id'), FILTER_VALIDATE_INT);
        $employeeId = $requested === false ? ($user->employeeId ?? (int) ($employees[0]['id'] ?? 0)) : (int) $requested;
        if (!$employeeId) {
            throw new HttpException(422, 'Choose an employee to view attendance.');
        }
        if (!$access->canAccess($user, $employeeId)) {
            throw new HttpException(403, 'You do not have access to that employee.');
        }
        $attendance = new AttendanceService();
        $employee = (new CatalogService())->employee($user->companyId, $employeeId);
        $records = $attendance->history($user->companyId, $employeeId);
        return $this->view('attendance/history', [
            'user' => $user,
            'employee' => $employee,
            'employees' => $employees,
            'selectedEmployeeId' => $employeeId,
            'records' => $records,
            'activityEvidence' => $attendance->activityEvidence($user->companyId, $employeeId, $records,
                (string) ($employee['timezone'] ?: config('app.timezone', 'Africa/Johannesburg'))),
            'events' => $attendance->events($user->companyId, $employeeId),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function correct(Request $request, string $id): Response
    {
        $user = Auth::user();
        $corrections = new AttendanceCorrectionService();
        try {
            $employeeId = $corrections->employeeIdForEvent($user->companyId, (int) $id);
            if (!(new EmployeeAccessService())->canAccess($user, $employeeId)) {
                throw new HttpException(403, 'You do not have access to that employee.');
            }
            $corrections->correct(
                $user->companyId,
                (int) $id,
                (string) $request->input('corrected_event'),
                (string) $request->input('corrected_at'),
                (string) $request->input('reason'),
                $user->id,
                $request
            );
            Session::flash('success', 'Correction appended. The original event remains unchanged.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect(app_url('/attendance/history?employee_id=' . ($employeeId ?? '')));
    }

    public function addMissing(Request $request, string $id): Response
    {
        $user = Auth::user();
        $corrections = new AttendanceCorrectionService();
        try {
            $submittedAttendanceId = filter_var($request->input('attendance_id'), FILTER_VALIDATE_INT);
            $attendanceId = $submittedAttendanceId === false ? (int) $id : (int) $submittedAttendanceId;
            $employeeId = $corrections->employeeIdForAttendance($user->companyId, $attendanceId);
            if (!(new EmployeeAccessService())->canAccess($user, $employeeId)) {
                throw new HttpException(403, 'You do not have access to that employee.');
            }
            $corrections->appendMissing(
                $user->companyId,
                $attendanceId,
                (string) $request->input('event'),
                (string) $request->input('occurred_at'),
                (string) $request->input('reason'),
                $user->id,
                $request
            );
            Session::flash('success', 'Missing event appended and daily totals recalculated.');
        } catch (HttpException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect(app_url('/attendance/history?employee_id=' . ($employeeId ?? '')));
    }
}
