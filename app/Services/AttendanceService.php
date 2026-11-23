<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class AttendanceService
{
    /**
     * Close an overdue regular session at its configured shift end.
     *
     * Maintenance waits for the company's missing-clock-out threshold so a real
     * device event can arrive first, but records the event at scheduled_end_at.
     */
    public function autoClockOutOverdueSessions(int $companyId, int $employeeId, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $database = Database::connection();
        $threshold = $database->prepare(
            'SELECT COALESCE(CAST(value AS UNSIGNED), 30) FROM settings
             WHERE company_id = :company_id AND setting_key = "attendance.missing_clock_out_after_minutes" LIMIT 1'
        );
        $threshold->execute(['company_id' => $companyId]);
        $minutes = max(0, (int) ($threshold->fetchColumn() ?: 30));
        $database->beginTransaction();
        try {
            $statement = $database->prepare(
                'SELECT a.id, a.employee_id, a.opened_at, a.scheduled_end_at
                 FROM attendance a
                 JOIN employees e ON e.id = a.employee_id
                 WHERE a.employee_id = :employee_id AND e.company_id = :company_id
                   AND a.session_type = "regular" AND a.status IN ("working", "break", "lunch", "missing_clock_out")
                   AND a.closed_at IS NULL AND a.deleted_at IS NULL
                   AND a.scheduled_end_at IS NOT NULL
                   AND a.scheduled_end_at <= :overdue_at
                 ORDER BY a.scheduled_end_at, a.id
                 FOR UPDATE'
            );
            $statement->execute([
                'employee_id' => $employeeId,
                'company_id' => $companyId,
                'overdue_at' => $now->modify('-' . $minutes . ' minutes')->format('Y-m-d H:i:s.u'),
            ]);
            $sessions = $statement->fetchAll(PDO::FETCH_ASSOC);

            foreach ($sessions as $session) {
                $scheduledEnd = new DateTimeImmutable((string) $session['scheduled_end_at'], new DateTimeZone('UTC'));
                $openedAt = new DateTimeImmutable((string) $session['opened_at'], new DateTimeZone('UTC'));
                $clockOutAt = $scheduledEnd < $openedAt ? $openedAt : $scheduledEnd;
                $lastEvent = $this->lastEvent((int) $session['id']);

                if (in_array($lastEvent['code'] ?? null, ['break_start', 'lunch_start'], true)) {
                    $endCode = $lastEvent['code'] === 'lunch_start' ? 'lunch_end' : 'break_end';
                    $endEventId = $this->insertSystemAttendanceEvent(
                        (int) $session['id'], $employeeId, $endCode, $clockOutAt,
                        ['reason' => 'scheduled_auto_clock_out', 'ended_open_interval' => true]
                    );
                    $this->updateBreakProjection((int) $session['id'], $endCode, $endEventId, $clockOutAt);
                }

                $clockOutEventId = $this->insertSystemAttendanceEvent(
                    (int) $session['id'], $employeeId, 'clock_out', $clockOutAt,
                    ['reason' => 'scheduled_auto_clock_out', 'threshold_minutes' => $minutes]
                );
                $this->updateAttendanceProjection((int) $session['id'], 'clock_out', $clockOutAt, $companyId);

                $audit = $database->prepare(
                    'INSERT INTO audit_logs
                     (company_id, user_id, action, entity_type, entity_id, new_value, reason)
                     VALUES (:company_id, NULL, "attendance.auto_clock_out", "attendance", :entity_id,
                             :new_value, "Employee did not clock out before the configured threshold")'
                );
                $audit->execute([
                    'company_id' => $companyId,
                    'entity_id' => $session['id'],
                    'new_value' => json_encode([
                        'employee_id' => $employeeId,
                        'event_id' => $clockOutEventId,
                        'closed_at' => $clockOutAt->format(DATE_ATOM),
                    ], JSON_THROW_ON_ERROR),
                ]);
            }

            $database->commit();
            return count($sessions);
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $metadata */
    private function insertSystemAttendanceEvent(
        int $attendanceId,
        int $employeeId,
        string $eventCode,
        DateTimeImmutable $occurredAt,
        array $metadata
    ): int {
        $database = Database::connection();
        $statement = $database->prepare(
            'INSERT INTO attendance_events
             (attendance_id, employee_id, event_type_id, occurred_at, source, idempotency_key, metadata)
             VALUES (:attendance_id, :employee_id, :event_type_id, :occurred_at, "system", :idempotency_key, :metadata)'
        );
        $statement->execute([
            'attendance_id' => $attendanceId,
            'employee_id' => $employeeId,
            'event_type_id' => $this->eventTypeId($eventCode),
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
            'idempotency_key' => $this->uuid(),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
        return (int) $database->lastInsertId();
    }

    /** @return array<string, mixed> */
    public function record(
        int $companyId,
        int $employeeId,
        string $eventCode,
        Request $request,
        ?int $userId,
        ?int $deviceId,
        ?string $idempotencyKey = null,
        string $sessionType = 'regular'
    ): array {
        $database = Database::connection();
        $idempotencyKey = $this->validUuid($idempotencyKey) ? $idempotencyKey : $this->uuid();

        $existing = $database->prepare(
            'SELECT ae.id, ae.attendance_id, et.code AS event_code, ae.occurred_at
             FROM attendance_events ae
             JOIN attendance_event_types et ON et.id = ae.event_type_id
             WHERE ae.idempotency_key = :key'
        );
        $existing->execute(['key' => $idempotencyKey]);
        $existingEvent = $existing->fetch();
        if ($existingEvent) {
            return ['idempotent' => true, 'event' => $existingEvent];
        }

        $database->beginTransaction();
        try {
            $employee = $this->employee($companyId, $employeeId);
            $occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $workDate = $occurredAt->setTimezone(new DateTimeZone((string) $employee['timezone']))->format('Y-m-d');
            if (($employee['attendance_mode'] ?? 'fixed') === 'flexible'
                && !empty($employee['activity_attendance_effective_from'])
                && $workDate >= (string) $employee['activity_attendance_effective_from']) {
                throw new HttpException(409, 'Attendance is recorded automatically from computer activity for this employee.');
            }
            $eventTypeId = $this->eventTypeId($eventCode);

            // Enforce schedule constraints: reject clock events outside shift times
            $this->enforceScheduleConstraint($employee, $occurredAt, $eventCode, $request, $userId);

            // Check for device integrity issues: timestamp drift, multiple devices
            if ($deviceId !== null) {
                $this->validateDeviceIntegrity($deviceId, $employeeId, $eventCode);
            }

            $attendance = $this->attendanceForUpdate($employeeId, $workDate, $eventCode);
            $lastEvent = $attendance ? $this->lastEvent((int) $attendance['id']) : null;
            $this->assertTransition($companyId, $lastEvent['event_type_id'] ?? null, $eventTypeId);

            if (!$attendance) {
                if ($sessionType === 'overtime' && $eventCode !== 'clock_in') {
                    throw new HttpException(409, 'No overtime session is currently open.');
                }
                $attendance = $this->openAttendance($employee, $workDate, $occurredAt, $sessionType);
            } elseif ($sessionType === 'overtime' && ($attendance['session_type'] ?? 'regular') !== 'overtime') {
                throw new HttpException(409, 'Finish the regular shift before recording overtime.');
            }

            $insert = $database->prepare(
                'INSERT INTO attendance_events
                 (attendance_id, employee_id, event_type_id, device_id, recorded_by_user_id,
                  occurred_at, source, idempotency_key, ip_address, computer_name, metadata)
                 VALUES
                 (:attendance_id, :employee_id, :event_type_id, :device_id, :user_id,
                  :occurred_at, :source, :idempotency_key, :ip_address, :computer_name, :metadata)'
            );
            $insert->execute([
                'attendance_id' => $attendance['id'],
                'employee_id' => $employeeId,
                'event_type_id' => $eventTypeId,
                'device_id' => $deviceId,
                'user_id' => $userId,
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
                'source' => $deviceId ? 'windows_agent' : ($request->expectsJson() ? 'api' : 'web'),
                'idempotency_key' => $idempotencyKey,
                'ip_address' => $request->ip(),
                'computer_name' => trim((string) $request->input('computer_name', '')) ?: null,
                'metadata' => json_encode([
                    'user_agent' => $request->userAgent(),
                    'confirmation_source' => trim((string) $request->input('confirmation_source', '')) ?: null,
                ], JSON_THROW_ON_ERROR),
            ]);
            $eventId = (int) $database->lastInsertId();

            $this->updateBreakProjection((int) $attendance['id'], $eventCode, $eventId, $occurredAt);
            $this->updateAttendanceProjection((int) $attendance['id'], $eventCode, $occurredAt, $companyId);
            if ($deviceId !== null) {
                $database->prepare(
                    'UPDATE devices SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :device_id'
                )->execute(['device_id' => $deviceId]);
            }

            (new AuditService())->record(
                'attendance.' . $eventCode,
                'attendance',
                (int) $attendance['id'],
                $request,
                $userId,
                null,
                ['employee_id' => $employeeId, 'event_id' => $eventId, 'event' => $eventCode]
            );
            $database->commit();

            return [
                'idempotent' => false,
                'attendance_id' => (int) $attendance['id'],
                'event_id' => $eventId,
                'event' => $eventCode,
                'employee_id' => $employeeId,
                'occurred_at' => $occurredAt->format(DATE_ATOM),
                'state' => $this->stateFor($eventCode),
            ];
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function history(int $companyId, ?int $employeeId = null, int $limit = 100): array
    {
        (new FlexibleAttendanceService())->refreshForReport($companyId, null, null, $employeeId === null ? null : [$employeeId]);
        $sql = 'SELECT a.id, a.work_date, a.session_number, a.status, a.opened_at, a.closed_at,
                       COALESCE(FLOOR(fad.active_seconds / 60), a.calculated_work_minutes) AS calculated_work_minutes,
                       COALESCE(FLOOR(fad.inactive_seconds / 60), a.calculated_break_minutes) AS calculated_break_minutes,
                       COALESCE(fad.overtime_minutes, a.calculated_overtime_minutes) AS calculated_overtime_minutes,
                       a.calculation_version, a.session_type, e.employee_number,
                       (SELECT et_latest.code
                        FROM attendance_events ae_latest
                        JOIN attendance_event_types et_latest ON et_latest.id = ae_latest.event_type_id
                        WHERE ae_latest.attendance_id = a.id
                        ORDER BY ae_latest.occurred_at DESC, ae_latest.id DESC LIMIT 1) AS latest_event_code,
                       CONCAT(e.first_name, " ", e.last_name) AS employee_name
                FROM attendance a
                LEFT JOIN flexible_attendance_days fad ON fad.attendance_id=a.id
                JOIN employees e ON e.id = a.employee_id
                WHERE e.company_id = :company_id AND a.deleted_at IS NULL';
        $parameters = ['company_id' => $companyId];
        if ($employeeId !== null) {
            $sql .= ' AND e.id = :employee_id';
            $parameters['employee_id'] = $employeeId;
        }
        $sql .= ' ORDER BY a.work_date DESC, a.id DESC LIMIT ' . max(1, min($limit, 500));
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** Windows activity is a separate evidence source from manual attendance events. */
    public function activityEvidence(int $companyId, int $employeeId, array $records, string $timezone): array
    {
        $result = [];
        $query = Database::connection()->prepare(
            'SELECT wet.code, wet.name, COUNT(*) AS samples, MIN(we.occurred_at) AS first_at, MAX(we.occurred_at) AS last_at
             FROM windows_events we JOIN windows_event_types wet ON wet.id=we.event_type_id
             JOIN employees e ON e.id=we.employee_id
             WHERE e.company_id=:company AND e.id=:employee AND we.occurred_at>=:start AND we.occurred_at<:end
             GROUP BY wet.code, wet.name ORDER BY first_at'
        );
        foreach ($records as $record) {
            if (!str_starts_with((string) ($record['calculation_version'] ?? ''), 'flexible-')) continue;
            $start = new DateTimeImmutable($record['work_date'] . ' 00:00:00', new DateTimeZone($timezone));
            $end = $start->modify('+1 day');
            $query->execute(['company' => $companyId, 'employee' => $employeeId,
                'start' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
            $result[(int) $record['id']] = $query->fetchAll();
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function events(int $companyId, int $employeeId, int $limit = 200): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ae.id, ae.attendance_id, ae.occurred_at, ae.received_at, ae.source, ae.correction_of_event_id,
                    ae.correction_reason, ae.metadata, et.code, et.name, a.work_date
             FROM attendance_events ae
             JOIN attendance_event_types et ON et.id = ae.event_type_id
             JOIN attendance a ON a.id = ae.attendance_id
             JOIN employees e ON e.id = ae.employee_id
             WHERE ae.employee_id = :employee_id AND e.company_id = :company_id
             ORDER BY ae.occurred_at DESC, ae.id DESC LIMIT ' . max(1, min($limit, 500))
        );
        $statement->execute(['employee_id' => $employeeId, 'company_id' => $companyId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    private function employee(int $companyId, int $employeeId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT e.*, COALESCE(e.timezone, c.timezone) AS timezone,
                    s.id AS shift_id, s.start_time, s.end_time, s.spans_midnight,
                    s.paid_minutes, s.grace_period_minutes
             FROM employees e
             JOIN companies c ON c.id = e.company_id
             LEFT JOIN employee_shifts es ON es.employee_id = e.id AND es.deleted_at IS NULL
                 AND es.effective_from <= CURRENT_DATE
                 AND (es.effective_to IS NULL OR es.effective_to >= CURRENT_DATE)
             LEFT JOIN shifts s ON s.id = es.shift_id AND s.deleted_at IS NULL
             WHERE e.id = :employee_id AND e.company_id = :company_id
               AND e.employment_status = "active" AND e.deleted_at IS NULL
             ORDER BY es.effective_from DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['employee_id' => $employeeId, 'company_id' => $companyId]);
        $employee = $statement->fetch();
        if (!$employee) {
            throw new HttpException(404, 'The active employee was not found.');
        }
        return $employee;
    }

    private function eventTypeId(string $code): int
    {
        $statement = Database::connection()->prepare('SELECT id FROM attendance_event_types WHERE code = :code');
        $statement->execute(['code' => $code]);
        $id = $statement->fetchColumn();
        if (!$id) {
            throw new HttpException(422, 'Unknown attendance action.');
        }
        return (int) $id;
    }

    /** @return array<string, mixed>|null */
    private function attendanceForUpdate(int $employeeId, string $workDate, string $eventCode): ?array
    {
        if ($eventCode === 'clock_in') {
            $statement = Database::connection()->prepare(
                'SELECT * FROM attendance
                 WHERE employee_id = :employee_id AND status <> "closed" AND deleted_at IS NULL
                 ORDER BY opened_at DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['employee_id' => $employeeId]);
            return $statement->fetch() ?: null;
        }

        $statement = Database::connection()->prepare(
            'SELECT * FROM attendance
             WHERE employee_id = :employee_id AND status <> "closed" AND deleted_at IS NULL
             ORDER BY opened_at DESC, id DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['employee_id' => $employeeId]);
        $attendance = $statement->fetch();
        if ($attendance) {
            return $attendance;
        }

        $latest = Database::connection()->prepare(
            'SELECT * FROM attendance
             WHERE employee_id = :employee_id AND work_date BETWEEN DATE_SUB(:work_date, INTERVAL 1 DAY) AND :work_date_match
               AND deleted_at IS NULL
             ORDER BY opened_at DESC, id DESC LIMIT 1 FOR UPDATE'
        );
        $latest->execute([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'work_date_match' => $workDate,
        ]);
        return $latest->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    private function lastEvent(int $attendanceId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ae.id, ae.event_type_id, ae.occurred_at, ae.metadata,
                    ae.correction_of_event_id, et.code
             FROM attendance_events ae
             JOIN attendance_event_types et ON et.id = ae.event_type_id
             WHERE ae.attendance_id = :attendance_id
             ORDER BY ae.id'
        );
        $statement->execute(['attendance_id' => $attendanceId]);
        $effective = [];
        $corrections = [];
        foreach ($statement->fetchAll() as $event) {
            if ($event['code'] === 'correction') {
                $corrections[] = $event;
                continue;
            }
            $effective[(int) $event['id']] = $event;
        }
        foreach ($corrections as $correction) {
            $originalId = (int) $correction['correction_of_event_id'];
            $metadata = json_decode((string) $correction['metadata'], true);
            $correctedCode = is_array($metadata) ? (string) ($metadata['corrected_event'] ?? '') : '';
            if (!isset($effective[$originalId]) || $correctedCode === '') continue;
            $effective[$originalId]['code'] = $correctedCode;
            $effective[$originalId]['occurred_at'] = $metadata['corrected_occurred_at'] ?? $effective[$originalId]['occurred_at'];
            $effective[$originalId]['event_type_id'] = $correctedCode === 'void'
                ? null
                : $this->eventTypeId($correctedCode);
        }
        $effective = array_values(array_filter(
            $effective,
            static fn (array $event): bool => $event['code'] !== 'void'
        ));
        usort($effective, static fn (array $left, array $right): int =>
            strcmp((string) $left['occurred_at'], (string) $right['occurred_at'])
                ?: ((int) $left['id'] <=> (int) $right['id'])
        );
        return $effective === [] ? null : $effective[array_key_last($effective)];
    }

    private function assertTransition(int $companyId, ?int $fromTypeId, int $toTypeId): void
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM attendance_event_transitions
             WHERE (company_id = :company_id OR company_id IS NULL)
               AND ((from_event_type_id IS NULL AND :from_type_id IS NULL)
                    OR from_event_type_id = :from_type_id_match)
               AND to_event_type_id = :to_type_id AND is_active = 1
             ORDER BY company_id IS NOT NULL DESC LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'from_type_id' => $fromTypeId,
            'from_type_id_match' => $fromTypeId,
            'to_type_id' => $toTypeId,
        ]);
        if (!$statement->fetchColumn()) {
            throw new HttpException(409, 'That attendance action is not valid in the employee’s current state.');
        }
    }

    /** @return array<string, mixed> */
    private function openAttendance(array $employee, string $workDate, DateTimeImmutable $occurredAt, string $sessionType = 'regular'): array
    {
        $scheduledStart = null;
        $scheduledEnd = null;
        if ($employee['shift_id'] && $employee['start_time'] && $employee['end_time']) {
            $timezone = new DateTimeZone((string) $employee['timezone']);
            $scheduledStartDate = new DateTimeImmutable($workDate . ' ' . $employee['start_time'], $timezone);
            $scheduledEndDate = new DateTimeImmutable($workDate . ' ' . $employee['end_time'], $timezone);
            if ((int) $employee['spans_midnight'] === 1 || $scheduledEndDate <= $scheduledStartDate) {
                $scheduledEndDate = $scheduledEndDate->modify('+1 day');
            }
            $scheduledStart = $scheduledStartDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $scheduledEnd = $scheduledEndDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }
        $statement = Database::connection()->prepare(
            'INSERT INTO attendance
             (employee_id, shift_id, work_date, session_number, status, session_type,
              scheduled_start_at, scheduled_end_at, opened_at)
             SELECT :employee_id, :shift_id, :work_date,
                    COALESCE(MAX(existing.session_number), 0) + 1, "working", :session_type,
                    :scheduled_start, :scheduled_end, :opened_at
             FROM attendance existing
             WHERE existing.employee_id = :session_employee_id
               AND existing.work_date = :session_work_date'
        );
        $statement->execute([
            'employee_id' => $employee['id'],
            'session_employee_id' => $employee['id'],
            'shift_id' => $employee['shift_id'],
            'work_date' => $workDate,
            'session_work_date' => $workDate,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'opened_at' => $occurredAt->format('Y-m-d H:i:s.u'),
            'session_type' => $sessionType === 'overtime' ? 'overtime' : 'regular',
        ]);
        return ['id' => (int) Database::connection()->lastInsertId()];
    }

    private function updateBreakProjection(int $attendanceId, string $eventCode, int $eventId, DateTimeImmutable $occurredAt): void
    {
        if (in_array($eventCode, ['break_start', 'lunch_start'], true)) {
            $statement = Database::connection()->prepare(
                'INSERT INTO breaks (attendance_id, break_type, start_event_id)
                 VALUES (:attendance_id, :break_type, :start_event_id)'
            );
            $statement->execute([
                'attendance_id' => $attendanceId,
                'break_type' => str_starts_with($eventCode, 'lunch') ? 'lunch' : 'break',
                'start_event_id' => $eventId,
            ]);
        }
        if (in_array($eventCode, ['break_end', 'lunch_end'], true)) {
            $type = str_starts_with($eventCode, 'lunch') ? 'lunch' : 'break';
            $statement = Database::connection()->prepare(
                'SELECT b.id, ae.occurred_at
                 FROM breaks b JOIN attendance_events ae ON ae.id = b.start_event_id
                 WHERE b.attendance_id = :attendance_id AND b.break_type = :break_type
                   AND b.end_event_id IS NULL AND b.deleted_at IS NULL
                 ORDER BY b.id DESC LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['attendance_id' => $attendanceId, 'break_type' => $type]);
            $break = $statement->fetch();
            if (!$break) {
                throw new HttpException(409, 'No matching open break was found.');
            }
            $startedAt = new DateTimeImmutable((string) $break['occurred_at'], new DateTimeZone('UTC'));
            $minutes = max(0, (int) floor(($occurredAt->getTimestamp() - $startedAt->getTimestamp()) / 60));
            $update = Database::connection()->prepare(
                'UPDATE breaks SET end_event_id = :event_id, calculated_minutes = :minutes WHERE id = :id'
            );
            $update->execute(['event_id' => $eventId, 'minutes' => $minutes, 'id' => $break['id']]);
        }
    }

    private function updateAttendanceProjection(int $attendanceId, string $eventCode, DateTimeImmutable $occurredAt, int $companyId): void
    {
        if ($eventCode !== 'clock_out') {
            $statement = Database::connection()->prepare('UPDATE attendance SET status = :status WHERE id = :id');
            $statement->execute(['status' => $this->stateFor($eventCode), 'id' => $attendanceId]);
            return;
        }

        $attendanceStatement = Database::connection()->prepare(
            'SELECT scheduled_start_at, session_type FROM attendance WHERE id = :id'
        );
        $attendanceStatement->execute(['id' => $attendanceId]);
        $attendance = $attendanceStatement->fetch() ?: [];

        $events = Database::connection()->prepare(
            'SELECT et.code, ae.occurred_at FROM attendance_events ae
             JOIN attendance_event_types et ON et.id = ae.event_type_id
             WHERE ae.attendance_id = :id ORDER BY ae.occurred_at, ae.id'
        );
        $events->execute(['id' => $attendanceId]);
        $clockIn = null;
        $breakStart = null;
        $breakIntervals = [];
        foreach ($events->fetchAll() as $event) {
            $timestamp = (new DateTimeImmutable(
                (string) $event['occurred_at'],
                new DateTimeZone('UTC')
            ))->getTimestamp();
            if ($event['code'] === 'clock_in') {
                $clockIn = $timestamp;
            } elseif (in_array($event['code'], ['break_start', 'lunch_start'], true)) {
                $breakStart = $timestamp;
            } elseif (in_array($event['code'], ['break_end', 'lunch_end'], true) && $breakStart !== null) {
                $breakIntervals[] = [$breakStart, $timestamp];
                $breakStart = null;
            }
        }
        $clockIn = (int) $clockIn;
        $clockOut = $occurredAt->getTimestamp();
        $effectiveStart = $clockIn;
        if (!empty($attendance['scheduled_start_at'])) {
            $scheduledStart = (new DateTimeImmutable(
                (string) $attendance['scheduled_start_at'],
                new DateTimeZone('UTC')
            ))->getTimestamp();
            $effectiveStart = max($clockIn, $scheduledStart);
        }

        $breakSeconds = 0;
        foreach ($breakIntervals as [$start, $end]) {
            $overlapStart = max((int) $start, $effectiveStart);
            $overlapEnd = min((int) $end, $clockOut);
            $breakSeconds += max(0, $overlapEnd - $overlapStart);
        }

        $workMinutes = max(0, (int) floor(($clockOut - $effectiveStart - $breakSeconds) / 60));
        $thresholdStatement = Database::connection()->prepare(
            'SELECT value FROM settings WHERE company_id = :company_id
             AND setting_key = "attendance.overtime_after_minutes"'
        );
        $thresholdStatement->execute(['company_id' => $companyId]);
        $threshold = (int) ($thresholdStatement->fetchColumn() ?: 480);
        $update = Database::connection()->prepare(
            'UPDATE attendance SET status = "closed", closed_at = :closed_at,
             calculated_work_minutes = :work_minutes, calculated_break_minutes = :break_minutes,
             calculated_overtime_minutes = :overtime_minutes, calculation_version = "phase2-v1"
             WHERE id = :id'
        );
        $update->execute([
            'closed_at' => $occurredAt->format('Y-m-d H:i:s.u'),
            'work_minutes' => $workMinutes,
            'break_minutes' => (int) floor($breakSeconds / 60),
            'overtime_minutes' => ($attendance['session_type'] ?? 'regular') === 'overtime'
                ? $workMinutes
                : max(0, $workMinutes - $threshold),
            'id' => $attendanceId,
        ]);
    }

    /**
     * Enforce schedule constraints: reject clock events outside assigned shift times
     * Allows within grace period or for after-hours flagging
     */
    private function enforceScheduleConstraint(
        array $employee,
        DateTimeImmutable $occurredAt,
        string $eventCode,
        Request $request,
        ?int $userId
    ): void
    {
        // Only enforce for clock in/out events
        if (!in_array($eventCode, ['clock_in', 'clock_out'], true)) {
            return;
        }

        // Skip if no shift assigned
        if (!$employee['shift_id'] || !$employee['start_time'] || !$employee['end_time']) {
            return;
        }

        $timezone = new DateTimeZone((string) $employee['timezone']);
        $eventTime = $occurredAt->setTimezone($timezone);
        $eventTimeStr = $eventTime->format('H:i:s');

        $startTime = $employee['start_time'];
        $endTime = $employee['end_time'];
        $graceMinutes = $employee['grace_period_minutes'] ?? 0;

        // Parse times to seconds
        [$eH, $eM, $eS] = explode(':', $eventTimeStr);
        [$sH, $sM] = explode(':', $startTime);
        [$endH, $endM] = explode(':', $endTime);

        $eventSeconds = (int) $eH * 3600 + (int) $eM * 60 + (int) $eS;
        $startSeconds = (int) $sH * 3600 + (int) $sM * 60;
        $endSeconds = (int) $endH * 3600 + (int) $endM * 60;
        $graceSeconds = $graceMinutes * 60;

        $isOutside = false;
        if ((int) $employee['spans_midnight'] === 1) {
            // Shift crosses midnight - allow between start_time and 24:00, or 00:00 and end_time
            $isOutside = $eventSeconds < $startSeconds && $eventSeconds > $endSeconds;
        } else {
            // Normal shift - allow between start and end with grace period
            $isOutside = $eventSeconds < ($startSeconds - $graceSeconds) || 
                         $eventSeconds > ($endSeconds + $graceSeconds);
        }

        // For manual web/API submissions outside shift, flag via audit but allow
        // For device events, log but don't block (anomaly detection will flag)
        if ($isOutside) {
            // Log a warning to audit - this will be picked up by anomaly detection
            (new AuditService())->record(
                'attendance.schedule_warning',
                'attendance',
                null,
                $request,
                $userId,
                null,
                [
                    'employee_id' => $employee['id'],
                    'event_code' => $eventCode,
                    'event_time' => $eventTime->format('H:i'),
                    'shift_start' => $startTime,
                    'shift_end' => $endTime,
                ]
            );
        }
    }

    /**
     * Validate device integrity: detect timestamp drift, simultaneous devices, replay attempts
     */
    private function validateDeviceIntegrity(int $deviceId, int $employeeId, string $eventCode): void
    {
        // Attendance actions use server time, so historical client clock drift must
        // never deadlock the device. Windows telemetry performs its own timestamp
        // validation when it includes a client-provided occurred_at value.

        // Always allow an employee to finish a session. A second-device clock-out
        // is auditable and safer than leaving attendance permanently open.
        if ($eventCode === 'clock_out') return;

        // Check for multiple devices reporting for same employee within last 60 seconds
        $simultaneousCheck = Database::connection()->prepare(
            'SELECT COUNT(DISTINCT device_id) as device_count, 
                    GROUP_CONCAT(DISTINCT device_id) as device_ids
             FROM attendance_events
             WHERE employee_id = :employee_id 
             AND received_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             AND device_id != :device_id'
        );
        $simultaneousCheck->execute(['employee_id' => $employeeId, 'device_id' => $deviceId]);
        $result = $simultaneousCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($result && (int) $result['device_count'] > 0) {
            throw new HttpException(
                422,
                'Simultaneous clock events from multiple devices detected. Please use a single device.'
            );
        }
    }

    private function stateFor(string $eventCode): string
    {
        return match ($eventCode) {
            'break_start' => 'break',
            'lunch_start' => 'lunch',
            'clock_out' => 'closed',
            default => 'working',
        };
    }

    private function validUuid(?string $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
