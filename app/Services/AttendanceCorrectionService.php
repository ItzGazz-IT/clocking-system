<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class AttendanceCorrectionService
{
    public function employeeIdForAttendance(int $companyId, int $attendanceId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT a.employee_id FROM attendance a
             JOIN employees e ON e.id = a.employee_id
             WHERE a.id = :attendance_id AND e.company_id = :company_id'
        );
        $statement->execute(['attendance_id' => $attendanceId, 'company_id' => $companyId]);
        $employeeId = $statement->fetchColumn();
        if (!$employeeId) {
            throw new HttpException(404, 'Attendance record not found.');
        }
        return (int) $employeeId;
    }

    public function employeeIdForEvent(int $companyId, int $eventId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT ae.employee_id FROM attendance_events ae
             JOIN employees e ON e.id = ae.employee_id
             WHERE ae.id = :event_id AND e.company_id = :company_id'
        );
        $statement->execute(['event_id' => $eventId, 'company_id' => $companyId]);
        $employeeId = $statement->fetchColumn();
        if (!$employeeId) {
            throw new HttpException(404, 'Attendance event not found.');
        }
        return (int) $employeeId;
    }

    public function correct(
        int $companyId,
        int $eventId,
        string $correctedCode,
        string $correctedAt,
        string $reason,
        int $userId,
        Request $request
    ): int {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new HttpException(422, 'Provide a correction reason of at least 10 characters.');
        }
        if (!in_array($correctedCode, ['clock_in', 'clock_out', 'break_start', 'break_end', 'lunch_start', 'lunch_end', 'void'], true)) {
            throw new HttpException(422, 'Choose a valid corrected event type.');
        }
        try {
            $correctedDate = new DateTimeImmutable($correctedAt, new DateTimeZone((string) config('app.timezone')));
        } catch (Throwable) {
            throw new HttpException(422, 'Choose a valid corrected date and time.');
        }
        if ($correctedDate > new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone')))) {
            throw new HttpException(422, 'A corrected event cannot be dated in the future.');
        }
        $database = Database::connection();
        $database->beginTransaction();
        try {
            $original = $database->prepare(
                'SELECT ae.attendance_id, ae.employee_id, et.code AS original_code, ae.occurred_at
                 FROM attendance_events ae
                 JOIN attendance_event_types et ON et.id = ae.event_type_id
                 JOIN employees e ON e.id = ae.employee_id
                 WHERE ae.id = :event_id AND e.company_id = :company_id FOR UPDATE'
            );
            $original->execute(['event_id' => $eventId, 'company_id' => $companyId]);
            $event = $original->fetch();
            if (!$event) {
                throw new HttpException(404, 'Attendance event not found.');
            }
            $correctionType = (int) $database->query(
                'SELECT id FROM attendance_event_types WHERE code = "correction"'
            )->fetchColumn();
            $insert = $database->prepare(
                'INSERT INTO attendance_events
                 (attendance_id, employee_id, event_type_id, recorded_by_user_id, occurred_at,
                  source, idempotency_key, ip_address, metadata, correction_of_event_id, correction_reason)
                 VALUES
                 (:attendance_id, :employee_id, :event_type_id, :user_id, UTC_TIMESTAMP(6),
                  "administrative", :key, :ip_address, :metadata, :original_id, :reason)'
            );
            $insert->execute([
                'attendance_id' => $event['attendance_id'],
                'employee_id' => $event['employee_id'],
                'event_type_id' => $correctionType,
                'user_id' => $userId,
                'key' => $this->uuid(),
                'ip_address' => $request->ip(),
                'metadata' => json_encode([
                    'original_event' => $event['original_code'],
                    'original_occurred_at' => $event['occurred_at'],
                    'corrected_event' => $correctedCode,
                    'corrected_occurred_at' => $correctedDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                ], JSON_THROW_ON_ERROR),
                'original_id' => $eventId,
                'reason' => $reason,
            ]);
            $correctionId = (int) $database->lastInsertId();
            $this->recalculate((int) $event['attendance_id'], $companyId);
            (new AuditService())->record(
                'attendance.corrected',
                'attendance_event',
                $eventId,
                $request,
                $userId,
                ['event' => $event['original_code'], 'occurred_at' => $event['occurred_at']],
                ['correction_event_id' => $correctionId, 'event' => $correctedCode, 'occurred_at' => $correctedAt],
                $reason
            );
            $database->commit();
            return $correctionId;
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    public function appendMissing(
        int $companyId,
        int $attendanceId,
        string $eventCode,
        string $occurredAt,
        string $reason,
        int $userId,
        Request $request
    ): int {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new HttpException(422, 'Provide a reason of at least 10 characters.');
        }
        if (!in_array($eventCode, ['clock_in', 'clock_out', 'break_start', 'break_end', 'lunch_start', 'lunch_end'], true)) {
            throw new HttpException(422, 'Choose a valid event type.');
        }
        try {
            $occurred = (new DateTimeImmutable(
                $occurredAt,
                new DateTimeZone((string) config('app.timezone'))
            ))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new HttpException(422, 'Choose a valid event date and time.');
        }

        $database = Database::connection();
        $database->beginTransaction();
        try {
            $record = $database->prepare(
                'SELECT a.employee_id, a.work_date, a.session_type, COALESCE(s.spans_midnight, 0) AS spans_midnight FROM attendance a
                 JOIN employees e ON e.id = a.employee_id
                 LEFT JOIN shifts s ON s.id = a.shift_id
                 WHERE a.id = :attendance_id AND e.company_id = :company_id FOR UPDATE'
            );
            $record->execute(['attendance_id' => $attendanceId, 'company_id' => $companyId]);
            $attendance = $record->fetch();
            if (!$attendance) {
                throw new HttpException(404, 'Attendance record not found.');
            }
            $employeeId = (int) $attendance['employee_id'];
            $localDate = $occurred->setTimezone(new DateTimeZone((string) config('app.timezone')));
            $workDate = new DateTimeImmutable((string) $attendance['work_date'], new DateTimeZone((string) config('app.timezone')));
            $allowedDates = [$workDate->format('Y-m-d')];
            if ((int) $attendance['spans_midnight'] === 1 || ($attendance['session_type'] ?? 'regular') === 'overtime') {
                $allowedDates[] = $workDate->modify('+1 day')->format('Y-m-d');
            }
            if (!in_array($localDate->format('Y-m-d'), $allowedDates, true)) {
                throw new HttpException(422, 'The event date must match the selected attendance day (or the following day for an overnight shift).');
            }
            if ($occurred > new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                throw new HttpException(422, 'A missing event cannot be dated in the future.');
            }
            $type = $database->prepare('SELECT id FROM attendance_event_types WHERE code = :code');
            $type->execute(['code' => $eventCode]);
            $eventTypeId = $type->fetchColumn();
            $insert = $database->prepare(
                'INSERT INTO attendance_events
                 (attendance_id, employee_id, event_type_id, recorded_by_user_id, occurred_at,
                  source, idempotency_key, ip_address, metadata, correction_reason)
                 VALUES
                 (:attendance_id, :employee_id, :event_type_id, :user_id, :occurred_at,
                  "administrative", :key, :ip_address, :metadata, :reason)'
            );
            $insert->execute([
                'attendance_id' => $attendanceId,
                'employee_id' => $employeeId,
                'event_type_id' => $eventTypeId,
                'user_id' => $userId,
                'occurred_at' => $occurred->format('Y-m-d H:i:s.u'),
                'key' => $this->uuid(),
                'ip_address' => $request->ip(),
                'metadata' => json_encode(['manual_entry' => true], JSON_THROW_ON_ERROR),
                'reason' => $reason,
            ]);
            $eventId = (int) $database->lastInsertId();
            $this->recalculate($attendanceId, $companyId);
            (new AuditService())->record(
                'attendance.missing_event_added',
                'attendance',
                $attendanceId,
                $request,
                $userId,
                null,
                ['event_id' => $eventId, 'event' => $eventCode, 'occurred_at' => $occurredAt],
                $reason
            );
            $database->commit();
            return $eventId;
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function recalculate(int $attendanceId, int $companyId): void
    {
        $database = Database::connection();
        $attendanceStatement = $database->prepare(
            'SELECT a.session_type, a.work_date, COALESCE(e.timezone, c.timezone) AS timezone,
                    COALESCE(s.spans_midnight, 0) AS spans_midnight
             FROM attendance a
             JOIN employees e ON e.id = a.employee_id
             JOIN companies c ON c.id = e.company_id
             LEFT JOIN shifts s ON s.id = a.shift_id
             WHERE a.id = :id'
        );
        $attendanceStatement->execute(['id' => $attendanceId]);
        $attendance = $attendanceStatement->fetch() ?: [];
        $sessionType = (string) ($attendance['session_type'] ?? 'regular');
        $events = $database->prepare(
            'SELECT ae.id, ae.occurred_at, ae.metadata, ae.correction_of_event_id, et.code
             FROM attendance_events ae
             JOIN attendance_event_types et ON et.id = ae.event_type_id
             WHERE ae.attendance_id = :attendance_id ORDER BY ae.id'
        );
        $events->execute(['attendance_id' => $attendanceId]);
        $effective = [];
        $corrections = [];
        foreach ($events->fetchAll() as $event) {
            if ($event['code'] === 'correction') {
                $corrections[] = $event;
                continue;
            }
            $effective[(int) $event['id']] = [
                'code' => $event['code'],
                'occurred_at' => $event['occurred_at'],
            ];
        }
        foreach ($corrections as $correction) {
            $originalId = (int) $correction['correction_of_event_id'];
            $metadata = json_decode((string) $correction['metadata'], true);
            if (isset($effective[$originalId], $metadata['corrected_event'], $metadata['corrected_occurred_at'])) {
                $effective[$originalId] = [
                    'code' => $metadata['corrected_event'],
                    'occurred_at' => $metadata['corrected_occurred_at'],
                ];
            }
        }
        $timezone = new DateTimeZone((string) ($attendance['timezone'] ?? config('app.timezone')));
        $workDate = (string) ($attendance['work_date'] ?? '');
        $allowedDates = [$workDate];
        if ((int) ($attendance['spans_midnight'] ?? 0) === 1 || $sessionType === 'overtime') {
            $allowedDates[] = (new DateTimeImmutable($workDate, $timezone))->modify('+1 day')->format('Y-m-d');
        }
        $effective = array_filter($effective, static function (array $event) use ($allowedDates, $timezone): bool {
            if ($event['code'] === 'void') return false;
            $eventDate = (new DateTimeImmutable((string) $event['occurred_at'], new DateTimeZone('UTC')))
                ->setTimezone($timezone)->format('Y-m-d');
            return in_array($eventDate, $allowedDates, true);
        });
        usort($effective, static fn (array $left, array $right): int =>
            strcmp((string) $left['occurred_at'], (string) $right['occurred_at'])
        );

        $clockIn = null;
        $clockOut = null;
        $breakStart = null;
        $breakIntervals = [];
        $breakIntervalIndex = null;
        foreach ($effective as $event) {
            $timestamp = (new DateTimeImmutable(
                (string) $event['occurred_at'],
                new DateTimeZone('UTC')
            ))->getTimestamp();
            if ($event['code'] === 'clock_in' && $clockIn === null) {
                $clockIn = $timestamp;
            } elseif ($event['code'] === 'clock_out') {
                $clockOut = $timestamp;
            } elseif (in_array($event['code'], ['break_start', 'lunch_start'], true)) {
                // Keep the earliest start when duplicate agent/manual samples
                // describe the same break. A start after an ended interval opens
                // a genuinely new interval.
                if ($breakStart === null || $breakIntervalIndex !== null) {
                    $breakStart = $timestamp;
                    $breakIntervalIndex = null;
                }
            } elseif (in_array($event['code'], ['break_end', 'lunch_end'], true) && $breakStart !== null) {
                if ($breakIntervalIndex === null) {
                    $breakIntervals[] = [$breakStart, $timestamp];
                    $breakIntervalIndex = array_key_last($breakIntervals);
                } else {
                    $breakIntervals[$breakIntervalIndex][1] = max($breakIntervals[$breakIntervalIndex][1], $timestamp);
                }
            }
        }
        $lastCode = $effective === [] ? null : $effective[array_key_last($effective)]['code'];
        $status = match ($lastCode) {
            null => 'closed',
            'clock_out' => 'closed',
            'break_start' => 'break',
            'lunch_start' => 'lunch',
            default => 'working',
        };
        $breakSeconds = 0;
        if ($clockIn !== null) {
            foreach ($breakIntervals as [$start, $end]) {
                $effectiveEnd = $clockOut === null ? $end : min($end, $clockOut);
                $breakSeconds += max(0, $effectiveEnd - max($start, $clockIn));
            }
        }
        $workMinutes = ($clockIn !== null && $clockOut !== null)
            ? max(0, (int) floor(($clockOut - $clockIn - $breakSeconds) / 60))
            : null;
        $threshold = $database->prepare(
            'SELECT value FROM settings WHERE company_id = :company_id
             AND setting_key = "attendance.overtime_after_minutes"'
        );
        $threshold->execute(['company_id' => $companyId]);
        $overtimeAfter = (int) ($threshold->fetchColumn() ?: 480);
        $update = $database->prepare(
            'UPDATE attendance SET status = :status, opened_at = :opened_at, closed_at = :closed_at,
             calculated_work_minutes = :work_minutes, calculated_break_minutes = :break_minutes,
             calculated_overtime_minutes = :overtime_minutes, calculation_version = "phase3-correction-v1"
             WHERE id = :id'
        );
        $update->execute([
            'status' => $status,
            'opened_at' => $clockIn === null ? null : gmdate('Y-m-d H:i:s', $clockIn),
            'closed_at' => $clockOut === null ? null : gmdate('Y-m-d H:i:s', $clockOut),
            'work_minutes' => $workMinutes,
            'break_minutes' => (int) floor($breakSeconds / 60),
            'overtime_minutes' => $workMinutes === null
                ? null
                : ($sessionType === 'overtime' ? $workMinutes : max(0, $workMinutes - $overtimeAfter)),
            'id' => $attendanceId,
        ]);
    }
}
