<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\EmployeeAccessService;
use DateTimeImmutable;
use DateTimeZone;

final class EmployeeController extends Controller
{
    public function show(Request $request): Response
    {
        $companyId = $request->attribute('device_company_id') ?? Auth::user()?->companyId;
        $employeeId = $request->attribute('device_employee_id') ?? Auth::user()?->employeeId;
        if (Auth::user()) {
            $requested = filter_var($request->input('employee_id'), FILTER_VALIDATE_INT);
            if ($requested !== false) {
                if (!(new EmployeeAccessService())->canAccess(Auth::user(), (int) $requested)) {
                    throw new HttpException(403, 'You do not have access to that employee.');
                }
                $employeeId = $requested;
            }
        }
        if (!$companyId || !$employeeId) {
            throw new HttpException(422, 'No employee is linked or selected.');
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone', 'Africa/Johannesburg'))))
            ->format('Y-m-d');
        $statement = Database::connection()->prepare(
            'SELECT e.id, e.employee_number, e.first_name, e.last_name, e.email,
                    e.employment_status, e.attendance_mode, e.activity_attendance_effective_from,
                    e.daily_target_minutes, e.idle_threshold_minutes, e.offline_timeout_minutes,
                    d.name AS department, s.name AS shift_name,
                    s.start_time, s.end_time, s.spans_midnight, s.grace_period_minutes, es.days_of_week,
                    COALESCE(e.timezone, c.timezone) AS timezone,
                    COALESCE((SELECT CAST(value AS UNSIGNED) FROM settings WHERE company_id = e.company_id AND setting_key = "attendance.absence_after_minutes" LIMIT 1), 60) AS absence_after_minutes,
                    COALESCE((SELECT CAST(value AS UNSIGNED) FROM settings WHERE company_id = e.company_id AND setting_key = "attendance.missing_clock_out_after_minutes" LIMIT 1), 30) AS missing_clock_out_after_minutes
             FROM employees e
             JOIN companies c ON c.id = e.company_id
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN employee_shifts es ON es.employee_id = e.id AND es.deleted_at IS NULL
                AND es.effective_from <= :effective_from_date
                AND (es.effective_to IS NULL OR es.effective_to >= :effective_to_date)
             LEFT JOIN shifts s ON s.id = es.shift_id
             WHERE e.id = :employee_id AND e.company_id = :company_id AND e.deleted_at IS NULL
             ORDER BY es.effective_from DESC LIMIT 1'
        );
        $statement->execute([
            'employee_id' => $employeeId,
            'company_id' => $companyId,
            'effective_from_date' => $today,
            'effective_to_date' => $today,
        ]);
        $employee = $statement->fetch();
        if (!$employee) {
            throw new HttpException(404, 'Employee not found.');
        }
        return $this->json(['data' => $employee]);
    }
}
