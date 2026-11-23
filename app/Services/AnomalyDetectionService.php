<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class AnomalyDetectionService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Check for idle time validation anomalies
     * Flags when idle_seconds don't align with actual work sessions
     */
    public function checkIdleTimeAnomalies(int $companyId, ?int $employeeId = null): int
    {
        $query = '
            SELECT we.id, we.employee_id, we.device_id, we.idle_seconds, we.occurred_at,
                   (SELECT ae_latest.occurred_at
                    FROM attendance_events ae_latest
                    WHERE ae_latest.employee_id = we.employee_id
                      AND ae_latest.occurred_at <= we.occurred_at
                    ORDER BY ae_latest.occurred_at DESC, ae_latest.id DESC
                    LIMIT 1) AS last_clock_event,
                   (SELECT et_latest.code
                    FROM attendance_events ae_latest
                    JOIN attendance_event_types et_latest ON et_latest.id = ae_latest.event_type_id
                    WHERE ae_latest.employee_id = we.employee_id
                      AND ae_latest.occurred_at <= we.occurred_at
                    ORDER BY ae_latest.occurred_at DESC, ae_latest.id DESC
                    LIMIT 1) AS last_event_code
            FROM windows_events we
            JOIN devices d ON d.id = we.device_id
            JOIN employees e ON e.id = we.employee_id
            WHERE d.company_id = :company_id
            AND we.occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND we.idle_seconds >= 7200
            AND (e.attendance_mode = "fixed" OR e.activity_attendance_effective_from IS NULL
                 OR DATE(we.occurred_at) < e.activity_attendance_effective_from)
        ';

        if ($employeeId !== null) {
            $query .= ' AND we.employee_id = :employee_id';
        }
        
        $stmt = $this->db->prepare($query);
        $params = ['company_id' => $companyId];
        if ($employeeId !== null) {
            $params['employee_id'] = $employeeId;
        }
        $stmt->execute($params);
        $anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $flagged = 0;
        foreach ($anomalies as $anomaly) {
            // Only flag if employee was supposed to be working
            if ($anomaly['last_event_code'] === 'clock_in') {
    $idleSeconds = (int) $anomaly['idle_seconds'];

    if ($idleSeconds >= 14400) {
        $severity = 'high';
    } elseif ($idleSeconds >= 7200) {
        $severity = 'medium';
    } else {
        continue;
    }

    $this->createAlert(
        $companyId,
        (int) $anomaly['employee_id'],
        'idle_time_extended',
        $severity,
        sprintf(
            'Employee idle for %.1f hours but still showing as clocked in',
            $idleSeconds / 3600
        ),
        null,
        null,
        (int) $anomaly['device_id'],
        (int) $anomaly['id'],
        ['idle_seconds' => $idleSeconds, 'windows_event_id' => $anomaly['id']]
    );

    $flagged++;
}
        }

        return $flagged;
    }

    /**
     * Check for timestamp manipulation
     * Flags events with occurred_at far in the past or future
     */
    public function checkTimestampIntegrity(int $companyId, ?int $employeeId = null): int
    {
        $query = '
            SELECT ae.id, ae.employee_id, ae.device_id, ae.occurred_at, ae.received_at,
                   TIMESTAMPDIFF(MINUTE, ae.occurred_at, ae.received_at) as drift_minutes
            FROM attendance_events ae
            JOIN devices d ON d.id = ae.device_id
            WHERE d.company_id = :company_id
            AND ae.source = "windows_agent"
            AND ae.occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND (
                TIMESTAMPDIFF(MINUTE, ae.occurred_at, ae.received_at) > 1440
                OR TIMESTAMPDIFF(MINUTE, ae.occurred_at, ae.received_at) < -60
            )
        ';

        if ($employeeId !== null) {
            $query .= ' AND ae.employee_id = :employee_id';
        }

        $stmt = $this->db->prepare($query);
        $params = ['company_id' => $companyId];
        if ($employeeId !== null) {
            $params['employee_id'] = $employeeId;
        }
        $stmt->execute($params);
        $anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $flagged = 0;
        foreach ($anomalies as $anomaly) {
            $this->createAlert(
                $companyId,
                (int) $anomaly['employee_id'],
                'timestamp_drift',
                'high',
                sprintf(
                    'Event timestamp drift: %d minutes (occurred %s SAST, received %s SAST)',
                    abs((int) $anomaly['drift_minutes']),
                    $this->localAlertDateTime((string) $anomaly['occurred_at']),
                    $this->localAlertDateTime((string) $anomaly['received_at'])
                ),
                (int) $anomaly['id'],
                (int) $anomaly['id'],
                (int) $anomaly['device_id'],
                null,
                ['drift_minutes' => $anomaly['drift_minutes']]
            );
            $flagged++;
        }

        return $flagged;
    }

    /**
     * Check for simultaneous device activity
     * Flags when same employee has events from multiple devices at same time
     */
    public function checkMultipleDeviceActivity(int $companyId, ?int $employeeId = null): int
    {
        $query = '
            SELECT ae.employee_id, ae.device_id, ae.occurred_at, 
                   GROUP_CONCAT(d.name, ", ") as devices,
                   COUNT(DISTINCT ae.device_id) as device_count
            FROM attendance_events ae
            JOIN devices d ON d.id = ae.device_id
            WHERE d.company_id = :company_id
            AND ae.source = "windows_agent"
            AND ae.occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY ae.employee_id, DATE_FORMAT(ae.occurred_at, "%Y-%m-%d %H:%i")
            HAVING COUNT(DISTINCT ae.device_id) > 1
        ';

        if ($employeeId !== null) {
            $query .= ' AND ae.employee_id = :employee_id';
        }

        $stmt = $this->db->prepare($query);
        $params = ['company_id' => $companyId];
        if ($employeeId !== null) {
            $params['employee_id'] = $employeeId;
        }
        $stmt->execute($params);
        $anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $flagged = 0;
        foreach ($anomalies as $anomaly) {
            $this->createAlert(
                $companyId,
                (int) $anomaly['employee_id'],
                'simultaneous_devices',
                'high',
                sprintf(
                    'Simultaneous clock events from %d devices: %s',
                    $anomaly['device_count'],
                    $anomaly['devices']
                ),
                null,
                null,
                (int) $anomaly['device_id'],
                null,
                ['device_count' => $anomaly['device_count'], 'devices' => $anomaly['devices']]
            );
            $flagged++;
        }

        return $flagged;
    }

    /**
     * Check for schedule violations
     * Flags clock events outside assigned shift times
     */
    public function checkScheduleViolations(int $companyId, ?int $employeeId = null): int
    {
        $query = '
            SELECT ae.id, ae.employee_id, ae.device_id, ae.occurred_at, 
                   et.code as event_code,
                   s.start_time, s.end_time, s.spans_midnight,
                   COALESCE(e.timezone, c.timezone) AS timezone
            FROM attendance_events ae
            JOIN attendance_event_types et ON et.id = ae.event_type_id
            JOIN attendance a ON a.id = ae.attendance_id
            JOIN shifts s ON s.id = a.shift_id
            JOIN employees e ON e.id = ae.employee_id
            JOIN companies c ON c.id = e.company_id
            WHERE e.company_id = :company_id
            AND ae.source IN ("windows_agent", "web", "api")
            AND ae.occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND et.code IN ("clock_in", "clock_out")
        ';

        if ($employeeId !== null) {
            $query .= ' AND ae.employee_id = :employee_id';
        }

        $stmt = $this->db->prepare($query);
        $params = ['company_id' => $companyId];
        if ($employeeId !== null) {
            $params['employee_id'] = $employeeId;
        }
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $flagged = 0;
        foreach ($events as $event) {
            $timezone = $event['timezone'] ?: (string) config('app.timezone', 'Africa/Johannesburg');
            $eventTime = (new DateTimeImmutable((string) $event['occurred_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($timezone));
            $eventTimeStr = $eventTime->format('H:i:s');

            // Convert shift times to comparable format
            $startTime = $event['start_time'];
            $endTime = $event['end_time'];

            // Check if event is outside shift window (allowing 30 min grace)
            $isOutside = $this->isTimeOutsideShift(
                $eventTimeStr,
                $startTime,
                $endTime,
                (bool) $event['spans_midnight'],
                60
            );

            if ($isOutside) {
                $this->createAlert(
                    $companyId,
                    (int) $event['employee_id'],
                    'schedule_violation',
                    'medium',
                    sprintf(
                        '%s at %s SAST outside shift hours (%s - %s)',
                        ucfirst(str_replace('_', ' ', $event['event_code'])),
                        $eventTime->format('H:i'),
                        $startTime,
                        $endTime
                    ),
                    null,
                    (int) $event['id'],
                    $event['device_id'],
                    null,
                    ['event_code' => $event['event_code'], 'shift_start' => $startTime, 'shift_end' => $endTime]
                );
                $flagged++;
            }
        }

        return $flagged;
    }

    /**
     * Check for unusual patterns: gaps, overlaps, rapid clocking
     */
    public function checkAnomalousPatterns(int $companyId, ?int $employeeId = null): int
    {
        $flagged = 0;

        // Check for rapid clock in/out (less than 1 minute)
        $rapidQuery = '
            SELECT ae1.id, ae1.employee_id, ae1.occurred_at as in_time,
                   ae2.occurred_at as out_time,
                   TIMESTAMPDIFF(SECOND, ae1.occurred_at, ae2.occurred_at) as duration_seconds
            FROM attendance_events ae1
            JOIN attendance_events ae2 ON ae2.employee_id = ae1.employee_id
                AND ae2.attendance_id = ae1.attendance_id
                AND ae2.occurred_at > ae1.occurred_at
            JOIN attendance_event_types et1 ON et1.id = ae1.event_type_id
            JOIN attendance_event_types et2 ON et2.id = ae2.event_type_id
            JOIN employees e ON e.id = ae1.employee_id
            WHERE e.company_id = :company_id
            AND et1.code = "clock_in" AND et2.code = "clock_out"
            AND ae1.occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND TIMESTAMPDIFF(SECOND, ae1.occurred_at, ae2.occurred_at) < 60
        ';

        if ($employeeId !== null) {
            $rapidQuery .= ' AND ae1.employee_id = :employee_id';
        }

        $stmt = $this->db->prepare($rapidQuery);
        $params = ['company_id' => $companyId];
        if ($employeeId !== null) {
            $params['employee_id'] = $employeeId;
        }
        $stmt->execute($params);
        $rapidEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rapidEvents as $event) {
            $this->createAlert(
                $companyId,
                (int) $event['employee_id'],
                'rapid_clock_cycle',
                'medium',
                sprintf(
                    'Clock in/out cycle completed in %d seconds at %s SAST',
                    $event['duration_seconds'],
                    $this->localAlertDateTime((string) $event['in_time'])
                ),
                null,
                (int) $event['id'],
                null,
                null,
                ['duration_seconds' => $event['duration_seconds']]
            );
            $flagged++;
        }

        return $flagged;
    }

    /**
     * Check for IP/geofencing violations if network access log is configured
     */
    public function checkNetworkAnomolies(int $companyId, ?int $employeeId = null): int
    {
        $query = '
            SELECT nal.id, nal.employee_id, nal.device_id, nal.ip_address, 
                   nal.country_code, nal.event_type, nal.occurred_at,
                   d.name as device_name
            FROM network_access_log nal
            JOIN devices d ON d.id = nal.device_id
            WHERE d.company_id = :company_id
            AND nal.is_expected = FALSE
            AND nal.occurred_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ';

        if ($employeeId !== null) {
            $query .= ' AND nal.employee_id = :employee_id';
        }

        $stmt = $this->db->prepare($query);
        $params = ['company_id' => $companyId];
        if ($employeeId !== null) {
            $params['employee_id'] = $employeeId;
        }
        $stmt->execute($params);
        $anomalies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $flagged = 0;
        foreach ($anomalies as $anomaly) {
            $location = $anomaly['country_code'] ? sprintf(' from %s', $anomaly['country_code']) : '';
            $this->createAlert(
                $companyId,
                (int) $anomaly['employee_id'],
                'unexpected_network',
                'medium',
                sprintf(
                    'Device %s accessed from unexpected IP %s%s',
                    $anomaly['device_name'],
                    $anomaly['ip_address'],
                    $location
                ),
                null,
                null,
                (int) $anomaly['device_id'],
                null,
                ['ip_address' => $anomaly['ip_address'], 'country_code' => $anomaly['country_code']]
            );
            $flagged++;
        }

        return $flagged;
    }

    /**
     * Check for Windows agents that stopped reporting.
     * A hard process kill or machine-side uninstall cannot report after it is gone,
     * so this scan uses the device heartbeat timestamp as the fallback signal.
     */
    public function checkAgentOffline(int $companyId, ?int $employeeId = null): int
    {
        $query = '
            SELECT d.id AS device_id, d.employee_id, d.name AS device_name, d.hostname, d.last_seen_at,
                   TIMESTAMPDIFF(MINUTE, d.last_seen_at, NOW()) AS offline_minutes,
                   EXISTS (
                       SELECT 1 FROM attendance a
                       WHERE a.employee_id = d.employee_id
                         AND a.deleted_at IS NULL
                         AND a.closed_at IS NULL
                         AND a.status IN ("working", "break", "lunch")
                   ) AS is_clocked_in
            FROM devices d
            JOIN employees e ON e.id = d.employee_id
            WHERE d.company_id = :company_id
              AND d.employee_id IS NOT NULL
              AND d.is_active = TRUE
              AND d.deleted_at IS NULL
              AND e.deleted_at IS NULL
              AND e.employment_status = "active"
              AND d.last_seen_at IS NOT NULL
              AND d.last_seen_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ';

        if ($employeeId !== null) {
            $query .= ' AND d.employee_id = :employee_id';
        }

        $stmt = $this->db->prepare($query);
        $params = ['company_id' => $companyId];
        if ($employeeId !== null) {
            $params['employee_id'] = $employeeId;
        }
        $stmt->execute($params);

        $flagged = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $device) {
            // Offline reminders are useful only while this employee is meant to
            // be working. This suppresses nights, weekends and non-working days.
            if (!$this->isWithinAssignedShift($companyId, (int) $device['employee_id'])) {
                continue;
            }

            $offlineMinutes = max(0, (int) $device['offline_minutes']);
            $severity = (int) $device['is_clocked_in'] === 1 ? 'high' : 'medium';
            $this->createAlert(
                $companyId,
                (int) $device['employee_id'],
                'agent_offline',
                $severity,
                sprintf(
                    'Windows agent stopped reporting from %s%s. Last seen %d minutes ago.',
                    $device['device_name'],
                    $device['hostname'] ? ' (' . $device['hostname'] . ')' : '',
                    $offlineMinutes
                ),
                null,
                null,
                (int) $device['device_id'],
                null,
                [
                    'last_seen_at' => $device['last_seen_at'],
                    'offline_minutes' => $offlineMinutes,
                    'is_clocked_in' => (bool) $device['is_clocked_in'],
                ]
            );
            $flagged++;
        }

        return $flagged;
    }

    public function createAgentLifecycleAlert(
        int $companyId,
        int $employeeId,
        int $deviceId,
        string $eventCode,
        int $windowEventId,
        ?array $metadata = null
    ): void {
        $alertType = match ($eventCode) {
            'agent_uninstall' => 'agent_uninstalled',
            'agent_exit' => 'agent_exited',
            default => 'agent_lifecycle',
        };
        $severity = $eventCode === 'agent_uninstall' ? 'high' : 'medium';
        $label = match ($eventCode) {
            'agent_uninstall' => 'Windows agent uninstall was started',
            'agent_exit' => 'Windows agent was exited from the tray',
            default => 'Windows agent lifecycle event was reported',
        };

        $this->createAlert(
            $companyId,
            $employeeId,
            $alertType,
            $severity,
            $label . '. Activity tracking may stop until the agent is running again.',
            null,
            null,
            $deviceId,
            $windowEventId,
            $metadata,
            false
        );
    }

    /**
     * Backfill lifecycle alerts when the Windows event was stored but the
     * immediate alert insert failed. This keeps Evidence and Alerts consistent.
     */
    public function checkAgentLifecycleEvents(int $companyId): int
    {
        $statement = $this->db->prepare(
            'SELECT we.id, we.employee_id, we.device_id, wet.code, we.metadata
             FROM windows_events we
             JOIN windows_event_types wet ON wet.id = we.event_type_id
             JOIN devices d ON d.id = we.device_id
             LEFT JOIN anomaly_alerts aa ON aa.related_window_event_id = we.id
             WHERE d.company_id = :company_id
               AND wet.code IN ("agent_exit", "agent_uninstall")
               AND we.employee_id IS NOT NULL
               AND we.occurred_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
               AND aa.id IS NULL
             ORDER BY we.id'
        );
        $statement->execute(['company_id' => $companyId]);

        $created = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $event) {
            $metadata = json_decode((string) ($event['metadata'] ?? ''), true);
            $this->createAgentLifecycleAlert(
                $companyId,
                (int) $event['employee_id'],
                (int) $event['device_id'],
                (string) $event['code'],
                (int) $event['id'],
                is_array($metadata) ? $metadata : null
            );
            $created++;
        }

        return $created;
    }

    /**
     * Run all anomaly checks for a company
     * Returns total anomalies found
     */
    public function scanCompany(int $companyId): array
    {
        return [
            'idle_time_anomalies' => $this->checkIdleTimeAnomalies($companyId),
            'timestamp_integrity' => $this->checkTimestampIntegrity($companyId),
            'simultaneous_devices' => $this->checkMultipleDeviceActivity($companyId),
            'schedule_violations' => $this->checkScheduleViolations($companyId),
            'pattern_anomalies' => $this->checkAnomalousPatterns($companyId),
            'network_anomalies' => $this->checkNetworkAnomolies($companyId),
            'agent_offline' => $this->checkAgentOffline($companyId),
            'agent_lifecycle' => $this->checkAgentLifecycleEvents($companyId),
        ];
    }

    /**
     * Create an alert record
     */
    private function createAlert(
        int $companyId,
        int $employeeId,
        string $alertType,
        string $severity,
        string $description,
        ?int $attendanceId = null,
        ?int $eventId = null,
        ?int $deviceId = null,
        ?int $windowEventId = null,
        ?array $metadata = null,
        bool $deduplicate = true
    ): void {
        // Check if similar alert already exists to avoid duplicates
        if ($deduplicate) {
            $check = $this->db->prepare(
                'SELECT id FROM anomaly_alerts
                 WHERE employee_id = :employee_id
                 AND alert_type = :alert_type
                 AND (:device_id_null IS NULL OR related_device_id = :device_id_match)
                 AND status = "pending"
                 AND created_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)'
            );
            $check->execute([
                'employee_id' => $employeeId,
                'alert_type' => $alertType,
                'device_id_null' => $deviceId,
                'device_id_match' => $deviceId,
            ]);
            if ($check->fetch()) {
                return; // Alert already exists
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO anomaly_alerts
             (company_id, employee_id, alert_type, severity, description, 
              related_attendance_id, related_event_id, related_device_id, related_window_event_id, metadata, status)
             VALUES
             (:company_id, :employee_id, :alert_type, :severity, :description,
              :attendance_id, :event_id, :device_id, :window_event_id, :metadata, "pending")'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'alert_type' => $alertType,
            'severity' => $severity,
            'description' => $description,
            'attendance_id' => $attendanceId,
            'event_id' => $eventId,
            'device_id' => $deviceId,
            'window_event_id' => $windowEventId,
            'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    /**
     * Helper: Check if time is outside shift window
     */
    private function isTimeOutsideShift(
        string $eventTime,
        string $startTime,
        string $endTime,
        bool $spansMidnight,
        int $graceMinutes = 0
    ): bool {
        [$eH, $eM, $eS] = explode(':', $eventTime);
        [$sH, $sM] = explode(':', $startTime);
        [$endH, $endM] = explode(':', $endTime);

        $eventSeconds = (int) $eH * 3600 + (int) $eM * 60 + (int) $eS;
        $startSeconds = (int) $sH * 3600 + (int) $sM * 60;
        $endSeconds = (int) $endH * 3600 + (int) $endM * 60;
        $graceSeconds = $graceMinutes * 60;

        if ($spansMidnight) {
            // Shift crosses midnight
            return $eventSeconds < $startSeconds && $eventSeconds > $endSeconds;
        }

        // Normal shift
        return $eventSeconds < ($startSeconds - $graceSeconds) || 
               $eventSeconds > ($endSeconds + $graceSeconds);
    }

    private function localAlertDateTime(string $utcDateTime): string
    {
        return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) config('app.timezone', 'Africa/Johannesburg')))
            ->format('Y-m-d H:i:s');
    }

    private function isWithinAssignedShift(
        int $companyId,
        int $employeeId,
        ?DateTimeImmutable $now = null
    ): bool {
        $employee = $this->db->prepare(
            'SELECT COALESCE(e.timezone, c.timezone) AS timezone
             FROM employees e JOIN companies c ON c.id = e.company_id
             WHERE e.id = :employee_id AND e.company_id = :company_id
               AND e.employment_status = "active" AND e.deleted_at IS NULL'
        );
        $employee->execute(['employee_id' => $employeeId, 'company_id' => $companyId]);
        $timezoneName = $employee->fetchColumn();
        if (!$timezoneName) {
            return false;
        }

        $timezone = new DateTimeZone((string) $timezoneName);
        $localNow = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($timezone);
        $candidateDates = [$localNow, $localNow->modify('-1 day')];

        $assignment = $this->db->prepare(
            'SELECT es.days_of_week, s.start_time, s.end_time, s.spans_midnight
             FROM employee_shifts es
             JOIN shifts s ON s.id = es.shift_id AND s.is_active = 1 AND s.deleted_at IS NULL
             WHERE es.employee_id = :employee_id AND es.deleted_at IS NULL
               AND es.effective_from <= :work_date
               AND (es.effective_to IS NULL OR es.effective_to >= :work_date_end)
             ORDER BY es.effective_from DESC LIMIT 1'
        );

        foreach ($candidateDates as $candidateDate) {
            $workDate = $candidateDate->format('Y-m-d');
            $assignment->execute([
                'employee_id' => $employeeId,
                'work_date' => $workDate,
                'work_date_end' => $workDate,
            ]);
            $shift = $assignment->fetch(PDO::FETCH_ASSOC);
            if (!$shift) {
                continue;
            }

            $days = $shift['days_of_week'] ? json_decode((string) $shift['days_of_week'], true) : null;
            if (is_array($days)
                && !in_array((int) $candidateDate->format('N'), array_map('intval', $days), true)) {
                continue;
            }

            $start = new DateTimeImmutable($workDate . ' ' . $shift['start_time'], $timezone);
            $end = new DateTimeImmutable($workDate . ' ' . $shift['end_time'], $timezone);
            if ((int) $shift['spans_midnight'] === 1 || $end <= $start) {
                $end = $end->modify('+1 day');
            }
            if ($localNow >= $start && $localNow <= $end) {
                $notWorking = $this->db->prepare(
                    'SELECT
                        EXISTS (SELECT 1 FROM public_holidays ph
                                WHERE ph.company_id = :company_id AND ph.holiday_date = :holiday_date
                                  AND ph.deleted_at IS NULL)
                        OR EXISTS (SELECT 1 FROM leave_requests lr
                                   WHERE lr.employee_id = :employee_id AND lr.status = "approved"
                                     AND lr.deleted_at IS NULL
                                     AND :leave_date BETWEEN lr.start_date AND lr.end_date)'
                );
                $notWorking->execute([
                    'company_id' => $companyId,
                    'employee_id' => $employeeId,
                    'holiday_date' => $workDate,
                    'leave_date' => $workDate,
                ]);
                if ((int) $notWorking->fetchColumn() === 1) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }
}
