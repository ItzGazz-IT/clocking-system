<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class WeeklyReportService
{
    /** @return array{start:string,end:string,rows:list<array<string,mixed>>,employee_groups:list<array<string,mixed>>,totals:array<string,int>} */
    public function report(int $companyId, ?string $week = null, ?int $employeeId = null): array
    {
        $timezone = new DateTimeZone((string) config('app.timezone', 'Africa/Johannesburg'));
        $start = $this->weekStart($week, $timezone);
        $end = $start->add(new DateInterval('P6D'));

        (new FlexibleAttendanceService())->refreshForReport($companyId, $start->format('Y-m-d'), $end->format('Y-m-d'), $employeeId === null ? null : [$employeeId]);

        $employeeFilter = $employeeId !== null ? ' AND e.id = :employee_id' : '';
        $statement = Database::connection()->prepare(
            'SELECT e.id AS employee_id, e.employee_number, CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                    d.name AS department_name, e.attendance_mode, a.work_date, a.session_number, a.status,
                    a.opened_at, a.closed_at,
                    COALESCE(FLOOR(fad.active_seconds / 60), a.calculated_work_minutes) AS calculated_work_minutes,
                    COALESCE(FLOOR(fad.inactive_seconds / 60), a.calculated_break_minutes) AS calculated_break_minutes,
                    COALESCE(fad.overtime_minutes, a.calculated_overtime_minutes) AS calculated_overtime_minutes,
                    s.name AS shift_name
             FROM attendance a
             LEFT JOIN flexible_attendance_days fad ON fad.attendance_id = a.id
             JOIN employees e ON e.id = a.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN shifts s ON s.id = a.shift_id
             WHERE e.company_id = :company_id
               AND a.deleted_at IS NULL
               AND a.work_date BETWEEN :start_date AND :end_date
               ' . $employeeFilter . '
             ORDER BY e.last_name, e.first_name, a.work_date, a.session_number'
        );
        $parameters = [
            'company_id' => $companyId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ];
        if ($employeeId !== null) {
            $parameters['employee_id'] = $employeeId;
        }
        $statement->execute($parameters);
        $rows = $statement->fetchAll();

        $totals = ['work' => 0, 'break' => 0, 'overtime' => 0, 'sessions' => count($rows)];
        $employeeGroups = [];
        foreach ($rows as &$row) {
            $row['work_minutes'] = (int) ($row['calculated_work_minutes'] ?? 0);
            $row['break_minutes'] = (int) ($row['calculated_break_minutes'] ?? 0);
            $row['overtime_minutes'] = (int) ($row['calculated_overtime_minutes'] ?? 0);
            $totals['work'] += $row['work_minutes'];
            $totals['break'] += $row['break_minutes'];
            $totals['overtime'] += $row['overtime_minutes'];

            $employeeKey = (int) $row['employee_id'];
            $dateKey = (string) $row['work_date'];
            if (!isset($employeeGroups[$employeeKey])) {
                $employeeGroups[$employeeKey] = [
                    'employee_id' => $employeeKey,
                    'employee_number' => $row['employee_number'],
                    'employee_name' => $row['employee_name'],
                    'department_name' => $row['department_name'],
                    'attendance_mode' => $row['attendance_mode'],
                    'totals' => ['work' => 0, 'break' => 0, 'overtime' => 0, 'sessions' => 0],
                    'days' => [],
                ];
            }
            if (!isset($employeeGroups[$employeeKey]['days'][$dateKey])) {
                $day = new DateTimeImmutable($dateKey, $timezone);
                $employeeGroups[$employeeKey]['days'][$dateKey] = [
                    'work_date' => $dateKey,
                    'day_name' => $day->format('l'),
                    'first_in' => null,
                    'last_out' => null,
                    'status' => 'closed',
                    'work_minutes' => 0,
                    'break_minutes' => 0,
                    'overtime_minutes' => 0,
                    'sessions' => [],
                ];
            }

            $employeeGroups[$employeeKey]['totals']['work'] += $row['work_minutes'];
            $employeeGroups[$employeeKey]['totals']['break'] += $row['break_minutes'];
            $employeeGroups[$employeeKey]['totals']['overtime'] += $row['overtime_minutes'];
            $employeeGroups[$employeeKey]['totals']['sessions']++;
            $employeeGroups[$employeeKey]['days'][$dateKey]['work_minutes'] += $row['work_minutes'];
            $employeeGroups[$employeeKey]['days'][$dateKey]['break_minutes'] += $row['break_minutes'];
            $employeeGroups[$employeeKey]['days'][$dateKey]['overtime_minutes'] += $row['overtime_minutes'];
            $employeeGroups[$employeeKey]['days'][$dateKey]['sessions'][] = $row;

            if ($row['opened_at'] !== null && ($employeeGroups[$employeeKey]['days'][$dateKey]['first_in'] === null || $row['opened_at'] < $employeeGroups[$employeeKey]['days'][$dateKey]['first_in'])) {
                $employeeGroups[$employeeKey]['days'][$dateKey]['first_in'] = $row['opened_at'];
            }
            if ($row['closed_at'] !== null && ($employeeGroups[$employeeKey]['days'][$dateKey]['last_out'] === null || $row['closed_at'] > $employeeGroups[$employeeKey]['days'][$dateKey]['last_out'])) {
                $employeeGroups[$employeeKey]['days'][$dateKey]['last_out'] = $row['closed_at'];
            }
            if ($row['closed_at'] === null) {
                $employeeGroups[$employeeKey]['days'][$dateKey]['status'] = 'open';
            }
        }
        unset($row);

        foreach ($employeeGroups as &$group) {
            foreach ($group['days'] as &$day) {
                $sessionParts = [];
                foreach ($day['sessions'] as $session) {
                    $sessionParts[] = '#' . (string) $session['session_number'] . ' '
                        . ($session['opened_at'] ? local_datetime($session['opened_at'], 'H:i') : '-')
                        . ' to '
                        . ($session['closed_at'] ? local_datetime($session['closed_at'], 'H:i') : '-');
                }
                $day['session_summary'] = implode(', ', $sessionParts);
            }
            unset($day);
            $group['days'] = array_values($group['days']);
        }
        unset($group);

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'rows' => $rows,
            'employee_groups' => array_values($employeeGroups),
            'totals' => $totals,
        ];
    }

    private function weekStart(?string $week, DateTimeZone $timezone): DateTimeImmutable
    {
        if ($week !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1) {
            $date = new DateTimeImmutable($week, $timezone);
        } else {
            $date = new DateTimeImmutable('today', $timezone);
        }

        return $date->modify('monday this week');
    }
}
