<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttendanceService;
use App\Services\WindowsEventService;
use App\Services\EmployeeAccessService;

final class AttendanceController extends Controller
{
    public function clockIn(Request $request): Response { return $this->record($request, 'clock_in'); }
    public function clockOut(Request $request): Response { return $this->record($request, 'clock_out'); }
    public function startBreak(Request $request): Response { return $this->record($request, 'break_start'); }
    public function endBreak(Request $request): Response { return $this->record($request, 'break_end'); }
    public function startLunch(Request $request): Response { return $this->record($request, 'lunch_start'); }
    public function endLunch(Request $request): Response { return $this->record($request, 'lunch_end'); }
    public function startOvertime(Request $request): Response { return $this->record($request, 'clock_in', 'overtime'); }
    public function endOvertime(Request $request): Response { return $this->record($request, 'clock_out', 'overtime'); }

    public function windowsEvent(Request $request): Response
    {
        $deviceId = $request->attribute('device_id');
        if (!$deviceId) {
            throw new HttpException(403, 'Windows events require device authentication.');
        }
        $result = (new WindowsEventService())->record(
            (int) $deviceId,
            $request->attribute('device_employee_id'),
            $request->all()
        );
        return $this->json(['data' => $result], $result['idempotent'] ? 200 : 201);
    }

    public function index(Request $request): Response
    {
        [$companyId, $employeeId] = $this->context($request, false);
        $requestedEmployee = filter_var($request->input('employee_id'), FILTER_VALIDATE_INT);
        if ($requestedEmployee !== false && Auth::user()?->can('attendance.view.all')) {
            $employeeId = (int) $requestedEmployee;
        }
        $attendance = new AttendanceService();
        return $this->json(['data' => $attendance->history($companyId, $employeeId)]);
    }

    private function record(Request $request, string $eventCode, string $sessionType = 'regular'): Response
    {
        [$companyId, $employeeId] = $this->context($request, true);
        $result = (new AttendanceService())->record(
            $companyId,
            $employeeId,
            $eventCode,
            $request,
            Auth::user()?->id,
            $request->attribute('device_id'),
            (string) $request->input('idempotency_key', ''),
            $sessionType
        );
        return $this->json(['data' => $result], $result['idempotent'] ? 200 : 201);
    }

    /** @return array{int, int|null} */
    private function context(Request $request, bool $allowRequestedEmployee): array
    {
        if ($request->attribute('device_id')) {
            $employeeId = $request->attribute('device_employee_id');
            if (!$employeeId) {
                throw new HttpException(422, 'This device is not assigned to an employee.');
            }
            return [(int) $request->attribute('device_company_id'), (int) $employeeId];
        }
        $user = Auth::user() ?? throw new HttpException(401, 'Authentication is required.');
        $employeeId = $user->employeeId;
        $requested = filter_var($request->input('employee_id'), FILTER_VALIDATE_INT);
        if ($requested !== false) {
            if (!(new EmployeeAccessService())->canAccess($user, (int) $requested)) {
                throw new HttpException(403, 'You do not have access to that employee.');
            }
            $employeeId = (int) $requested;
        }
        if (!$allowRequestedEmployee && $user->can('attendance.view.all')) {
            return [$user->companyId, $employeeId];
        }
        if (!$employeeId) {
            throw new HttpException(422, 'No employee is linked or selected.');
        }
        return [$user->companyId, $employeeId];
    }
}
