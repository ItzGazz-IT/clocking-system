<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class FlexibleAttendanceService
{
    private static array $refreshed = [];

    /** Refresh live totals and repair old projections only within the authorised report scope. */
    public function refreshForReport(int $companyId, ?string $start = null, ?string $end = null, ?array $employeeIds = null): void
    {
        if ($employeeIds === []) return;
        $parameters = ['company' => $companyId];
        $where = '';
        if ($start !== null) { $where .= ' AND a.work_date >= :start'; $parameters['start'] = $start; }
        if ($end !== null) { $where .= ' AND a.work_date <= :end'; $parameters['end'] = $end; }
        if ($employeeIds !== null) {
            $keys = [];
            foreach (array_values($employeeIds) as $i => $id) {
                $keys[] = ':employee_' . $i;
                $parameters['employee_' . $i] = (int) $id;
            }
            $where .= ' AND e.id IN (' . implode(',', $keys) . ')';
        }
        $query = Database::connection()->prepare(
            'SELECT e.id, a.work_date, a.calculation_version, COALESCE(e.timezone,c.timezone) timezone
             FROM attendance a JOIN employees e ON e.id=a.employee_id JOIN companies c ON c.id=e.company_id
             WHERE e.company_id=:company AND e.attendance_mode="flexible" AND e.deleted_at IS NULL
               AND a.deleted_at IS NULL AND a.calculation_version IN ("flexible-v1","flexible-v2","flexible-v3","flexible-v4")' . $where . '
             ORDER BY a.work_date DESC' . ($start === null ? ' LIMIT 500' : '')
        );
        $query->execute($parameters);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $today = $now->setTimezone(new DateTimeZone((string) $row['timezone']))->format('Y-m-d');
            $key = $row['id'] . ':' . $row['work_date'];
            $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            if (!isset(self::$refreshed[$key]) && ($row['calculation_version'] !== 'flexible-v4' || $row['work_date'] >= $yesterday)) {
                $this->recalculateDay((int) $row['id'], (string) $row['work_date'], $now);
                self::$refreshed[$key] = true;
            }
        }
    }

    public function isEnabled(int $employeeId, ?DateTimeImmutable $at = null): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT attendance_mode, activity_attendance_effective_from, COALESCE(e.timezone, c.timezone) timezone
             FROM employees e JOIN companies c ON c.id = e.company_id
             WHERE e.id = :employee_id AND e.deleted_at IS NULL'
        );
        $statement->execute(['employee_id' => $employeeId]);
        $employee = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$employee || $employee['attendance_mode'] !== 'flexible' || !$employee['activity_attendance_effective_from']) {
            return false;
        }
        $localDate = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) $employee['timezone']))->format('Y-m-d');
        return $localDate >= (string) $employee['activity_attendance_effective_from'];
    }

    public function processEvent(int $employeeId, DateTimeImmutable $occurredAt): void
    {
        if (!$this->isEnabled($employeeId, $occurredAt)) return;
        $profile = $this->profile($employeeId);
        $date = $occurredAt->setTimezone(new DateTimeZone((string) $profile['timezone']))->format('Y-m-d');
        $this->recalculateDay($employeeId, $date);
        // Evidence near midnight can finish yesterday's interval or seed tomorrow's.
        $local = $occurredAt->setTimezone(new DateTimeZone((string) $profile['timezone']));
        $window = max((int) $profile['idle_threshold_minutes'], (int) $profile['offline_timeout_minutes']) + 1;
        if ($local->modify('-' . $window . ' minutes')->format('Y-m-d') !== $date)
            $this->recalculateDay($employeeId, $local->modify('-1 day')->format('Y-m-d'));
        $next = $local->modify('+1 day')->setTime(0, 0);
        if ($local->modify('+' . $window . ' minutes')->format('Y-m-d') !== $date
            && $next <= new DateTimeImmutable('now', new DateTimeZone('UTC')))
            $this->recalculateDay($employeeId, $next->format('Y-m-d'));
    }

    public function refreshOpenDays(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $employees = Database::connection()->query(
            'SELECT e.id, COALESCE(e.timezone, c.timezone) timezone
             FROM employees e JOIN companies c ON c.id = e.company_id
             WHERE e.attendance_mode = "flexible" AND e.activity_attendance_effective_from IS NOT NULL
               AND e.employment_status = "active" AND e.deleted_at IS NULL'
        )->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($employees as $employee) {
            $today = $now->setTimezone(new DateTimeZone((string) $employee['timezone']))->format('Y-m-d');
            foreach ([$today, (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d')] as $date) {
                if ($this->isEnabled((int) $employee['id'], new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone((string) $employee['timezone'])))) {
                    $this->recalculateDay((int) $employee['id'], $date, $now);
                    $count++;
                }
            }
        }
        return $count;
    }

    public function recalculateDay(int $employeeId, string $workDate, ?DateTimeImmutable $now = null): void
    {
        $db = Database::connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            // Serialize event/maintenance/report projections for the same employee.
            $lock = $db->prepare('SELECT id FROM employees WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $employeeId]);
            $this->calculateLockedDay($employeeId, $workDate, $now);
            if ($ownsTransaction) $db->commit();
        } catch (\Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    private function calculateLockedDay(int $employeeId, string $workDate, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $profile = $this->profile($employeeId);
        if ($profile['attendance_mode'] !== 'flexible' || !$profile['activity_attendance_effective_from']
            || $workDate < $profile['activity_attendance_effective_from']) return;

        $timezone = new DateTimeZone((string) $profile['timezone']);
        $utc = new DateTimeZone('UTC');
        $dayStart = (new DateTimeImmutable($workDate . ' 00:00:00', $timezone))->setTimezone($utc);
        $dayEnd = $dayStart->setTimezone($timezone)->modify('+1 day')->setTimezone($utc);
        if ($dayStart > $now) return;
        $window = max((int) $profile['idle_threshold_minutes'], (int) $profile['offline_timeout_minutes']) + 1;
        $evidenceStart = $dayStart->modify('-' . $window . ' minutes');
        $effectiveStart = (new DateTimeImmutable($profile['activity_attendance_effective_from'] . ' 00:00:00', $timezone))->setTimezone($utc);
        if ($evidenceStart < $effectiveStart) $evidenceStart = $effectiveStart;
        $evidenceEnd = min($dayEnd->modify('+' . $window . ' minutes'), $now);
        $eventStatement = Database::connection()->prepare(
            'SELECT we.occurred_at, we.idle_seconds, we.metadata, wet.code
             FROM windows_events we JOIN windows_event_types wet ON wet.id = we.event_type_id
             WHERE we.employee_id = :employee_id AND we.occurred_at >= :start_at AND we.occurred_at <= :end_at
             ORDER BY we.occurred_at, we.id'
        );
        $eventStatement->execute([
            'employee_id' => $employeeId,
            'start_at' => $evidenceStart->format('Y-m-d H:i:s.u'),
            'end_at' => $evidenceEnd->format('Y-m-d H:i:s.u'),
        ]);
        $events = $eventStatement->fetchAll(PDO::FETCH_ASSOC);
        if ($events === []) return;

        $isToday = $workDate === $now->setTimezone($timezone)->format('Y-m-d');
        $calculationEnd = min($now->getTimestamp(), $dayEnd->getTimestamp());
        $calculation = (new FlexibleWorkCalculator())->calculate(
            $events, $dayStart->getTimestamp(), $calculationEnd,
            (int) $profile['idle_threshold_minutes'] * 60,
            (int) $profile['offline_timeout_minutes'] * 60, $isToday
        );
        extract($calculation, EXTR_SKIP);
        if ($firstActive === null) {
            // Repair old projections created solely by a legacy process-start login.
            // Keep the row and original raw evidence available for audit/replay.
            $db = Database::connection();
            $find = $db->prepare('SELECT id FROM attendance WHERE employee_id=:employee AND work_date=:date
                AND calculation_version IN ("flexible-v1","flexible-v2","flexible-v3","flexible-v4") AND deleted_at IS NULL');
            $find->execute(['employee'=>$employeeId,'date'=>$workDate]);
            foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $db->prepare('UPDATE attendance SET status="closed",closed_at=opened_at,calculated_work_minutes=0,
                    calculated_break_minutes=0,calculated_overtime_minutes=0,calculation_version="flexible-v4" WHERE id=:id')->execute(['id'=>$id]);
                $db->prepare('UPDATE flexible_attendance_days SET active_seconds=0,inactive_seconds=0,overtime_minutes=0,
                    overtime_status="none",overtime_reviewed_by_user_id=NULL,overtime_reviewed_at=NULL,overtime_review_notes=NULL,
                    last_calculated_at=NOW(6) WHERE attendance_id=:id')->execute(['id'=>$id]);
            }
            return;
        }

        $target = (int) $profile['daily_target_minutes'];
        $rawMinutes = intdiv($activeSeconds, 60);
        $overtime = max(0, $rawMinutes - $target);
        $regular = min($rawMinutes, $target);
        $attendanceId = $this->attendanceId($profile, $workDate, $firstActive);
        $existing = Database::connection()->prepare('SELECT * FROM flexible_attendance_days WHERE attendance_id = :id');
        $existing->execute(['id' => $attendanceId]);
        $day = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
        $status = $overtime === 0 ? 'none' : 'pending';
        if ($day && (int) $day['overtime_minutes'] === $overtime && in_array($day['overtime_status'], ['approved', 'rejected'], true)) {
            $status = $day['overtime_status'];
        }
        $approvedOvertime = $status === 'approved' ? $overtime : 0;
        // Time after the final work interval is not a break. A locked PC may keep
        // sending heartbeats all night; those must not inflate daily inactivity.
        $spanEnd = $currentlyActive ? $calculationEnd : (int) $lastActiveAt;
        $inactive = max(0, $spanEnd - $firstActive - $activeSeconds);
        // A flexible employee remains clocked in while recent activity is still
        // arriving. Once activity stops, close at the last confirmed work event;
        // later activity reopens the same daily attendance record automatically.
        $closedAt = $currentlyActive || $lastActiveAt === null
            ? null
            : gmdate('Y-m-d H:i:s.u', $lastActiveAt);
        $attendanceStatus = $isToday ? ($currentlyActive ? 'working' : 'idle') : 'closed';

        $db = Database::connection();
        $db->prepare(
            'UPDATE attendance SET status = :status, opened_at = :opened_at, closed_at = :closed_at,
             calculated_work_minutes = :work_minutes, calculated_break_minutes = :inactive_minutes,
             calculated_overtime_minutes = :overtime_minutes, calculation_version = "flexible-v4"
             WHERE id = :id'
        )->execute([
            'status' => $attendanceStatus, 'opened_at' => gmdate('Y-m-d H:i:s', $firstActive),
            'closed_at' => $closedAt, 'work_minutes' => $regular,
            'inactive_minutes' => intdiv($inactive, 60), 'overtime_minutes' => $approvedOvertime, 'id' => $attendanceId,
        ]);
        $db->prepare(
            'INSERT INTO flexible_attendance_days
             (company_id, employee_id, attendance_id, work_date, active_seconds, inactive_seconds,
              target_minutes, overtime_minutes, overtime_status, last_activity_at, last_calculated_at)
             VALUES (:company_id,:employee_id,:attendance_id,:work_date,:active,:inactive,:target,:overtime,:status,:last_activity,NOW(6))
             ON DUPLICATE KEY UPDATE active_seconds=VALUES(active_seconds), inactive_seconds=VALUES(inactive_seconds),
              target_minutes=VALUES(target_minutes),
              overtime_reviewed_by_user_id=IF(overtime_minutes=VALUES(overtime_minutes) AND overtime_status=VALUES(overtime_status),overtime_reviewed_by_user_id,NULL),
              overtime_reviewed_at=IF(overtime_minutes=VALUES(overtime_minutes) AND overtime_status=VALUES(overtime_status),overtime_reviewed_at,NULL),
              overtime_review_notes=IF(overtime_minutes=VALUES(overtime_minutes) AND overtime_status=VALUES(overtime_status),overtime_review_notes,NULL),
              overtime_minutes=VALUES(overtime_minutes), overtime_status=VALUES(overtime_status),
              last_activity_at=VALUES(last_activity_at), last_calculated_at=VALUES(last_calculated_at)'
        )->execute([
            'company_id' => $profile['company_id'], 'employee_id' => $employeeId, 'attendance_id' => $attendanceId,
            'work_date' => $workDate, 'active' => $activeSeconds, 'inactive' => $inactive,
            'target' => $target, 'overtime' => $overtime, 'status' => $status,
            'last_activity' => gmdate('Y-m-d H:i:s', (int) $lastEventAt),
        ]);
    }

    /** @return array<string,mixed> */
    private function profile(int $employeeId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT e.*, COALESCE(e.timezone,c.timezone) timezone FROM employees e
             JOIN companies c ON c.id=e.company_id WHERE e.id=:id AND e.deleted_at IS NULL'
        );
        $statement->execute(['id' => $employeeId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: throw new \RuntimeException('Employee not found.');
    }

    /** @param array<string,mixed> $profile */
    private function attendanceId(array $profile, string $workDate, int $openedAt): int
    {
        $db = Database::connection();
        $find = $db->prepare('SELECT id FROM attendance WHERE employee_id=:employee_id AND work_date=:work_date AND calculation_version IN ("flexible-v1","flexible-v2","flexible-v3","flexible-v4") AND deleted_at IS NULL LIMIT 1');
        $find->execute(['employee_id' => $profile['id'], 'work_date' => $workDate]);
        $id = $find->fetchColumn();
        if ($id) return (int) $id;
        $insert = $db->prepare(
            'INSERT INTO attendance (employee_id,shift_id,work_date,session_number,status,session_type,opened_at,calculation_version)
             SELECT :employee_id,NULL,:work_date,COALESCE(MAX(a.session_number),0)+1,"working","regular",:opened_at,"flexible-v1"
             FROM attendance a WHERE a.employee_id=:employee_match AND a.work_date=:date_match'
        );
        $insert->execute([
            'employee_id' => $profile['id'], 'employee_match' => $profile['id'], 'work_date' => $workDate,
            'date_match' => $workDate, 'opened_at' => gmdate('Y-m-d H:i:s', $openedAt),
        ]);
        return (int) $db->lastInsertId();
    }
}
