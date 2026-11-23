<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class DashboardService
{
    /** Use the same employee snapshot for headline counts and individual cards. */
    public function automaticMetrics(array $cards): array
    {
        $counts = array_count_values(array_column($cards, 'status_group'));
        return [
            'working' => (int) ($counts['working'] ?? 0),
            'inactive' => (int) ($counts['inactive'] ?? 0),
            'not_started' => (int) ($counts['not_started'] ?? 0),
            'needs_attention' => (int) ($counts['needs_attention'] ?? 0),
            'employees' => count($cards),
            'today_minutes' => array_sum(array_column($cards, 'work_minutes')),
            'inactive_minutes' => array_sum(array_column($cards, 'break_minutes')),
        ];
    }

    /** One latest observation per employee, so recurring heartbeats cannot flood the list. */
    public function recentAgentActivity(int $companyId, int $limit = 12, ?array $employeeIds = null): array
    {
        if ($employeeIds === []) return [];
        $parameters = ['company_id' => $companyId, 'now' => gmdate('Y-m-d H:i:s')];
        $scope = '';
        if ($employeeIds !== null) {
            $keys = [];
            foreach (array_values($employeeIds) as $i => $id) {
                $keys[] = ':scope_' . $i;
                $parameters['scope_' . $i] = (int) $id;
            }
            $scope = ' AND e.id IN (' . implode(',', $keys) . ')';
        }
        $statement = Database::connection()->prepare(
            'SELECT we.occurred_at, wet.code, CONCAT(e.first_name," ",e.last_name) AS employee_name,
                    COALESCE(e.timezone,c.timezone) AS timezone
             FROM employees e JOIN companies c ON c.id=e.company_id
             JOIN windows_events we ON we.id=(SELECT latest.id FROM windows_events latest
                 WHERE latest.employee_id=e.id AND latest.occurred_at <= :now
                 ORDER BY latest.occurred_at DESC, latest.id DESC LIMIT 1)
             JOIN windows_event_types wet ON wet.id=we.event_type_id
             WHERE e.company_id=:company_id AND e.deleted_at IS NULL AND e.employment_status="active"' . $scope . '
             ORDER BY we.occurred_at DESC, we.id DESC LIMIT ' . max(1, min(50, $limit))
        );
        $statement->execute($parameters);
        return array_map(static function (array $row): array {
            $row['event_name'] = match ($row['code']) {
                'activity' => 'Computer active', 'idle' => 'Computer idle',
                'lock' => 'Session inactive', 'sleep' => 'Computer asleep',
                'wake' => 'Computer resumed', 'login' => 'Windows sign-in',
                'unlock' => 'Session unlocked', 'logout' => 'Windows sign-out',
                'shutdown' => 'Computer shut down', 'agent_exit' => 'Agent stopped',
                'agent_uninstall' => 'Agent removed', default => 'Agent observation',
            };
            return $row;
        }, $statement->fetchAll());
    }

    /** @return array<string, int|float> */
    public function metrics(int $companyId, ?array $employeeIds = null): array
    {
        if ($employeeIds === []) {
            return ['working' => 0, 'lunch' => 0, 'break' => 0, 'scheduled' => 0, 'awaiting' => 0, 'absent' => 0, 'late' => 0, 'missing_clock_out' => 0, 'today_minutes' => 0];
        }
        (new FlexibleAttendanceService())->refreshForReport($companyId, date('Y-m-d'), date('Y-m-d'), $employeeIds);
        $scope = '';
        $parameters = ['company_id' => $companyId, 'work_date' => date('Y-m-d')];
        if ($employeeIds !== null) {
            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $key = 'scope_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = (int) $employeeId;
            }
            $scope = ' AND e.id IN (' . implode(', ', $placeholders) . ')';
        }
        $statement = Database::connection()->prepare(
            'SELECT
                SUM(CASE WHEN a.status = "working" THEN 1 ELSE 0 END) AS working,
                SUM(CASE WHEN a.status = "lunch" THEN 1 ELSE 0 END) AS lunch,
                SUM(CASE WHEN a.status = "break" THEN 1 ELSE 0 END) AS on_break,
                SUM(CASE WHEN a.status = "missing_clock_out" THEN 1 ELSE 0 END) AS missing_clock_out,
                COALESCE(SUM(CASE WHEN e.attendance_mode = "flexible" THEN COALESCE(FLOOR(fad.active_seconds / 60), a.calculated_work_minutes, 0)
                    WHEN a.status = "closed" THEN a.calculated_work_minutes
                    ELSE GREATEST(0,
                        TIMESTAMPDIFF(MINUTE, a.opened_at, UTC_TIMESTAMP())
                        - COALESCE((
                            SELECT SUM(CASE
                                WHEN b.end_event_id IS NOT NULL THEN COALESCE(b.calculated_minutes, 0)
                                ELSE GREATEST(0, TIMESTAMPDIFF(MINUTE, start_event.occurred_at, UTC_TIMESTAMP()))
                            END)
                            FROM breaks b
                            JOIN attendance_events start_event ON start_event.id = b.start_event_id
                            WHERE b.attendance_id = a.id AND b.deleted_at IS NULL
                        ), 0)
                    ) END), 0) AS today_minutes
             FROM employees e
             LEFT JOIN attendance a ON a.employee_id = e.id AND a.work_date = :work_date AND a.deleted_at IS NULL
             LEFT JOIN flexible_attendance_days fad ON fad.attendance_id=a.id
             LEFT JOIN shifts s ON s.id = a.shift_id
             WHERE e.company_id = :company_id AND e.employment_status = "active" AND e.deleted_at IS NULL' . $scope
        );
        $statement->execute($parameters);
        $metrics = $statement->fetch() ?: [];

        $expected = $this->expectedAttendanceStates($companyId, $employeeIds);
        $expectedCounts = array_count_values(array_column($expected, 'status'));

        return [
            'working' => (int) ($metrics['working'] ?? 0),
            'lunch' => (int) ($metrics['lunch'] ?? 0),
            'break' => (int) ($metrics['on_break'] ?? 0),
            'scheduled' => (int) ($expectedCounts['scheduled'] ?? 0),
            'awaiting' => (int) ($expectedCounts['awaiting'] ?? 0),
            'absent' => (int) ($expectedCounts['absent'] ?? 0),
            'late' => count($this->lateEmployees($companyId, $employeeIds)),
            'missing_clock_out' => (int) ($metrics['missing_clock_out'] ?? 0),
            'today_minutes' => (int) ($metrics['today_minutes'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentActivity(int $companyId, int $limit = 12, ?array $employeeIds = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $scope = '';
        $parameters = ['company_id' => $companyId];
        if ($employeeIds !== null) {
            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $key = 'activity_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = (int) $employeeId;
            }
            $scope = ' AND e.id IN (' . implode(', ', $placeholders) . ')';
        }
        $statement = Database::connection()->prepare(
            'SELECT ae.occurred_at, et.name AS event_name,
                    CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                    e.employee_number
             FROM attendance_events ae
             JOIN attendance_event_types et ON et.id = ae.event_type_id
             JOIN employees e ON e.id = ae.employee_id
             WHERE e.company_id = :company_id' . $scope . '
             ORDER BY ae.occurred_at DESC, ae.id DESC LIMIT ' . max(1, min($limit, 50))
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function lateEmployees(int $companyId, ?array $employeeIds = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $scope = '';
        $parameters = ['company_id' => $companyId, 'work_date' => date('Y-m-d')];
        if ($employeeIds !== null) {
            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $key = 'late_scope_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = (int) $employeeId;
            }
            $scope = ' AND e.id IN (' . implode(', ', $placeholders) . ')';
        }
        $statement = Database::connection()->prepare(
            'SELECT e.id AS employee_id, CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                    a.opened_at, a.scheduled_start_at, COALESCE(s.grace_period_minutes, 0) AS grace_minutes,
                    TIMESTAMPDIFF(MINUTE, a.scheduled_start_at, a.opened_at) AS late_minutes
             FROM attendance a
             JOIN employees e ON e.id = a.employee_id
             LEFT JOIN shifts s ON s.id = a.shift_id
             WHERE e.company_id = :company_id AND a.work_date = :work_date AND a.deleted_at IS NULL
               AND a.session_type = "regular"
               AND a.opened_at > DATE_ADD(a.scheduled_start_at, INTERVAL COALESCE(s.grace_period_minutes, 0) MINUTE)' . $scope . '
             ORDER BY late_minutes DESC, employee_name'
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function employeeStatusCards(int $companyId, ?array $employeeIds = null): array
    {
        if ($employeeIds === []) return [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // Employee local dates can differ from the dashboard viewer's date.
        $firstDate = $now->modify('-1 day')->format('Y-m-d');
        $lastDate = $now->modify('+1 day')->format('Y-m-d');
        (new FlexibleAttendanceService())->refreshForReport($companyId, $firstDate, $lastDate, $employeeIds);
        $parameters = ['company_id'=>$companyId, 'first_date'=>$firstDate, 'last_date'=>$lastDate];
        $scope = '';
        if ($employeeIds !== null) {
            $keys = [];
            foreach (array_values($employeeIds) as $index => $id) {
                $keys[] = ':employee_' . $index;
                $parameters['employee_' . $index] = (int) $id;
            }
            $scope = ' AND e.id IN (' . implode(',', $keys) . ')';
        }
        $statement = Database::connection()->prepare(
            'SELECT e.id,e.employee_number,e.attendance_mode,e.offline_timeout_minutes,
                    COALESCE(e.timezone,c.timezone) AS timezone,
                    CONCAT(e.first_name," ",e.last_name) AS employee_name,d.name AS department_name,
                    a.id AS attendance_id,a.work_date,a.status,a.opened_at,a.closed_at,
                    COALESCE(FLOOR(fad.active_seconds/60),a.calculated_work_minutes,0) AS work_minutes,
                    COALESCE(FLOOR(fad.inactive_seconds/60),a.calculated_break_minutes,0) AS inactive_minutes,
                    (SELECT COUNT(*) FROM devices device WHERE device.employee_id=e.id
                        AND device.is_active=1 AND device.deleted_at IS NULL) AS agent_device_count,
                    (SELECT MAX(device.last_seen_at) FROM devices device WHERE device.employee_id=e.id
                        AND device.is_active=1 AND device.deleted_at IS NULL) AS agent_last_seen_at,
                    wet.code AS latest_windows_event,we.occurred_at AS latest_event_at,we.idle_seconds AS latest_idle_seconds
             FROM employees e JOIN companies c ON c.id=e.company_id
             LEFT JOIN departments d ON d.id=e.department_id
             LEFT JOIN attendance a ON a.employee_id=e.id AND a.work_date BETWEEN :first_date AND :last_date AND a.deleted_at IS NULL
             LEFT JOIN flexible_attendance_days fad ON fad.attendance_id=a.id
             LEFT JOIN windows_events we ON we.id=(SELECT latest.id FROM windows_events latest
                 WHERE latest.employee_id=e.id AND latest.occurred_at<=UTC_TIMESTAMP()
                 ORDER BY latest.occurred_at DESC,latest.id DESC LIMIT 1)
             LEFT JOIN windows_event_types wet ON wet.id=we.event_type_id
             WHERE e.company_id=:company_id AND e.employment_status="active" AND e.deleted_at IS NULL' . $scope . '
             ORDER BY e.first_name,e.last_name,a.opened_at'
        );
        $statement->execute($parameters);
        $cards = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];
            $zone = new \DateTimeZone((string) $row['timezone']);
            $today = $now->setTimezone($zone)->format('Y-m-d');
            if (!isset($cards[$id])) {
                $cards[$id] = [
                    'id'=>$id,'employee_number'=>$row['employee_number'],'employee_name'=>$row['employee_name'],
                    'department_name'=>$row['department_name'],'timezone'=>$row['timezone'],'work_date'=>$today,
                    'sessions'=>0,'first_in'=>null,'last_out'=>null,'work_minutes'=>0,'break_minutes'=>0,
                    'current_status'=>'','last_work_at'=>null,'offline_timeout_minutes'=>(int)$row['offline_timeout_minutes'],
                    'agent_device_count'=>(int)$row['agent_device_count'],'agent_last_seen_at'=>$row['agent_last_seen_at'],
                    'latest_windows_event'=>$row['latest_windows_event'],'latest_event_at'=>$row['latest_event_at'],
                    'latest_idle_seconds'=>(int)($row['latest_idle_seconds'] ?? 0),
                ];
            }
            if ($row['attendance_id'] === null || $row['work_date'] !== $today) continue;
            if ($row['status'] === 'closed' && (int)$row['work_minutes'] === 0 && $row['opened_at'] === $row['closed_at']) continue;
            $card = &$cards[$id];
            $card['sessions']++;
            $card['work_minutes'] += (int)$row['work_minutes'];
            $card['break_minutes'] += (int)$row['inactive_minutes'];
            if ($card['first_in'] === null || $row['opened_at'] < $card['first_in']) $card['first_in'] = $row['opened_at'];
            if ($row['closed_at'] !== null && ($card['last_out'] === null || $row['closed_at'] > $card['last_out'])) $card['last_out'] = $row['closed_at'];
            // The stored flexible status remains idle even though its work interval is closed.
            $card['current_status'] = (string)$row['status'];
            unset($card);
        }
        foreach ($cards as &$card) {
            $this->applyAutomaticStatus($card, $now);
        }
        unset($card);
        $order = ['working'=>0,'inactive'=>1,'needs_attention'=>2,'not_started'=>3];
        usort($cards, static fn(array $a,array $b) =>
            ($order[$a['status_group']] <=> $order[$b['status_group']]) ?: strcmp($a['employee_name'],$b['employee_name']));
        return array_values($cards);
    }

    private function applyAutomaticStatus(array &$card, \DateTimeImmutable $now): void
    {
        $utc = new \DateTimeZone('UTC');
        $cutoff = $now->modify('-' . max(1,$card['offline_timeout_minutes']) . ' minutes');
        $seen = $card['agent_last_seen_at'] ? new \DateTimeImmutable($card['agent_last_seen_at'],$utc) : null;
        $observed = $card['latest_event_at'] ? new \DateTimeImmutable($card['latest_event_at'],$utc) : null;
        $event = (string)$card['latest_windows_event'];
        $recent = $observed !== null && $observed >= $cutoff;
        $card['last_work_at'] = $card['last_out'];
        if ($card['current_status'] === 'working' && $observed !== null) {
            $lastWork = $observed;
            if ($event === 'idle') $lastWork = $lastWork->modify('-' . max(0,$card['latest_idle_seconds']) . ' seconds');
            if ($card['first_in'] !== null && $lastWork >= new \DateTimeImmutable($card['first_in'],$utc))
                $card['last_work_at'] = $lastWork->format('Y-m-d H:i:s');
        }
        $card['status_note'] = '';
        if ($card['agent_device_count'] === 0) {
            [$label,$group] = ['Agent not linked','needs_attention'];
            $card['status_note'] = 'No active device is linked to this employee.';
        } elseif ($seen === null || $observed === null) {
            [$label,$group] = ['No agent data','needs_attention'];
            $card['status_note'] = 'Waiting for the linked device to report activity.';
        } elseif (in_array($event,['agent_exit','agent_uninstall'],true)) {
            [$label,$group] = ['Agent stopped','needs_attention'];
            $card['status_note'] = 'Check that the agent is running.';
        } elseif (!$recent && $seen >= $cutoff) {
            [$label,$group] = ['Activity delayed','needs_attention'];
            $card['status_note'] = 'Device is connected; recent activity has not arrived yet.';
        } elseif (!$recent && !in_array($event,['sleep','logout','shutdown'],true)) {
            [$label,$group] = ['Agent offline','needs_attention'];
            $card['status_note'] = 'No recent agent observations; recorded work is retained.';
        } elseif ($card['first_in'] === null) {
            [$label,$group] = ['Not started','not_started'];
            $card['status_note'] = 'No working activity recorded for this local day.';
        } elseif (in_array($event,['sleep','logout','shutdown'],true)) {
            [$label,$group] = [$event === 'sleep' ? 'PC asleep' : 'Signed out','inactive'];
        } elseif ($event === 'lock') {
            [$label,$group] = ['Session inactive','inactive'];
        } elseif ($event === 'idle' || $card['current_status'] !== 'working') {
            [$label,$group] = ['Inactive','inactive'];
        } else {
            [$label,$group] = ['Working','working'];
        }
        $card['status_label'] = $label;
        $card['status_group'] = $group;
        $card['status_tone'] = match($group) { 'working'=>'working','inactive'=>'paused','needs_attention'=>'offline',default=>'idle' };
    }
    /** @return array<int, int> */
    private function idleTotalsForDate(int $companyId, ?array $employeeIds, string $date): array
    {
        if ($employeeIds === []) return [];
        $timezone = new \DateTimeZone((string) config('app.timezone', 'Africa/Johannesburg'));
        $utc = new \DateTimeZone('UTC');
        $localDay = new \DateTimeImmutable($date, $timezone);
        $start = $localDay->setTime(0, 0)->setTimezone($utc);
        $end = $localDay->modify('+1 day')->setTime(0, 0)->setTimezone($utc);
        $scope = '';
        $parameters = [
            'company_id' => $companyId,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ];
        if ($employeeIds !== null) {
            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $key = 'idle_scope_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = (int) $employeeId;
            }
            $scope = ' AND we.employee_id IN (' . implode(', ', $placeholders) . ')';
        }
        $statement = Database::connection()->prepare(
            'SELECT we.employee_id, we.occurred_at, we.idle_seconds, (SELECT MAX(ia.opened_at) FROM attendance ia
                 WHERE ia.employee_id=we.employee_id AND ia.deleted_at IS NULL
                   AND ia.opened_at <= we.occurred_at
                   AND we.occurred_at <= COALESCE(ia.closed_at, UTC_TIMESTAMP())) AS attendance_start
             FROM windows_events we
             JOIN devices d ON d.id = we.device_id
             JOIN windows_event_types wet ON wet.id = we.event_type_id
             WHERE d.company_id = :company_id AND wet.code = "idle"
               AND we.occurred_at >= :start_at AND we.occurred_at < :end_at
               AND EXISTS (
                   SELECT 1 FROM attendance idle_attendance
                   WHERE idle_attendance.employee_id = we.employee_id
                     AND idle_attendance.deleted_at IS NULL
                     AND we.occurred_at >= idle_attendance.opened_at
                     AND we.occurred_at <= COALESCE(idle_attendance.closed_at, UTC_TIMESTAMP())
               )' . $scope . '
             ORDER BY we.employee_id, we.occurred_at, we.id'
        );
        $statement->execute($parameters);

        $samples = [];
        foreach ($statement->fetchAll() as $row) $samples[(int) $row['employee_id']][] = $row;
        $totals = [];
        foreach ($samples as $employeeId => $rows) {
            $totals[$employeeId] = IdleIntervals::summarize($rows, $timezone)['idle_seconds'];
        }
        return $totals;
    }

    /** @return array<int, array{status:string,scheduled_time:string}> */
    private function expectedAttendanceStates(int $companyId, ?array $employeeIds): array
    {
        if ($employeeIds === []) return [];
        $today = date('Y-m-d');
        $scope = '';
        $parameters = ['company_id' => $companyId];
        if ($employeeIds !== null) {
            $placeholders = [];
            foreach (array_values($employeeIds) as $index => $employeeId) {
                $key = 'expected_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = (int) $employeeId;
            }
            $scope = ' AND e.id IN (' . implode(', ', $placeholders) . ')';
        }
        $statement = Database::connection()->prepare(
            'SELECT e.id, COALESCE(e.timezone, c.timezone) AS timezone,
                    s.start_time, es.days_of_week,
                    EXISTS(SELECT 1 FROM attendance a WHERE a.employee_id = e.id AND a.work_date = :attendance_date AND a.session_type = "regular" AND a.deleted_at IS NULL) AS has_attendance,
                    EXISTS(SELECT 1 FROM public_holidays ph WHERE ph.company_id = e.company_id AND ph.holiday_date = :holiday_date AND ph.deleted_at IS NULL) AS is_holiday,
                    EXISTS(SELECT 1 FROM leave_requests lr WHERE lr.employee_id = e.id AND lr.status = "approved" AND lr.deleted_at IS NULL AND :leave_date BETWEEN lr.start_date AND lr.end_date) AS is_on_leave,
                    COALESCE((SELECT CAST(value AS UNSIGNED) FROM settings WHERE company_id = e.company_id AND setting_key = "attendance.absence_after_minutes" LIMIT 1), 60) AS absence_after_minutes
             FROM employees e
             JOIN companies c ON c.id = e.company_id
             JOIN employee_shifts es ON es.employee_id = e.id AND es.deleted_at IS NULL
                AND es.effective_from <= :assignment_date
                AND (es.effective_to IS NULL OR es.effective_to >= :assignment_end)
             JOIN shifts s ON s.id = es.shift_id AND s.is_active = 1 AND s.deleted_at IS NULL
             WHERE e.company_id = :company_id AND e.employment_status = "active" AND e.deleted_at IS NULL' . $scope . '
             ORDER BY e.id, es.effective_from DESC'
        );
        $statement->execute($parameters + [
            'attendance_date' => $today,
            'holiday_date' => $today,
            'leave_date' => $today,
            'assignment_date' => $today,
            'assignment_end' => $today,
        ]);
        $states = [];
        foreach ($statement->fetchAll() as $row) {
            $employeeId = (int) $row['id'];
            if (isset($states[$employeeId]) || (int) $row['has_attendance'] === 1 || (int) $row['is_holiday'] === 1 || (int) $row['is_on_leave'] === 1) continue;
            $timezone = new \DateTimeZone((string) $row['timezone']);
            $now = new \DateTimeImmutable('now', $timezone);
            $localDate = $now->format('Y-m-d');
            $days = $row['days_of_week'] ? json_decode((string) $row['days_of_week'], true) : null;
            if (is_array($days) && !in_array((int) $now->format('N'), array_map('intval', $days), true)) continue;
            $start = new \DateTimeImmutable($localDate . ' ' . $row['start_time'], $timezone);
            $absenceAt = $start->modify('+' . max(0, (int) $row['absence_after_minutes']) . ' minutes');
            $status = $now < $start ? 'scheduled' : ($now <= $absenceAt ? 'awaiting' : 'absent');
            $states[$employeeId] = ['status' => $status, 'scheduled_time' => $start->format('H:i')];
        }
        return $states;
    }
}
