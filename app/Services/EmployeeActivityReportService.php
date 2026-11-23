<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class EmployeeActivityReportService
{
    /** @return array<string, mixed>|null */
    public function report(int $companyId, int $employeeId, ?string $startValue, ?string $endValue): ?array
    {
        $db = Database::connection();
        $employeeStatement = $db->prepare(
            'SELECT e.id, e.employee_number, e.first_name, e.last_name,
                    CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                    d.name AS department_name, c.name AS company_name,
                    COALESCE(e.timezone, c.timezone) AS timezone
             FROM employees e
             JOIN companies c ON c.id = e.company_id
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE e.id = :employee_id AND e.company_id = :company_id AND e.deleted_at IS NULL'
        );
        $employeeStatement->execute(['employee_id' => $employeeId, 'company_id' => $companyId]);
        $employee = $employeeStatement->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            return null;
        }

        $timezone = new DateTimeZone((string) ($employee['timezone'] ?: config('app.timezone', 'Africa/Johannesburg')));
        [$localStart, $localEnd, $utcStart, $utcEnd] = $this->range($startValue, $endValue, $timezone);
        (new FlexibleAttendanceService())->refreshForReport($companyId, $localStart->format('Y-m-d'), $localEnd->format('Y-m-d'), [$employeeId]);
        $parameters = [
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'start_at' => $utcStart->format('Y-m-d H:i:s'),
            'end_at' => $utcEnd->format('Y-m-d H:i:s'),
        ];

        $days = [];
        $period = new DatePeriod($localStart, new DateInterval('P1D'), $localEnd->modify('+1 day'));
        foreach ($period as $day) {
            $date = $day->format('Y-m-d');
            $days[$date] = [
                'date' => $date,
                'day_name' => $day->format('D'),
                'first_in' => null,
                'last_out' => null,
                'work_seconds' => 0,
                'break_seconds' => 0,
                'late_minutes' => 0,
                'idle_seconds' => 0,
                'longest_idle_seconds' => 0,
                'idle_episodes' => 0,
                'idle_samples' => 0,
                'tracked_seconds' => 0,
                'productive_seconds' => 0,
                'neutral_seconds' => 0,
                'distracting_seconds' => 0,
                'unclassified_seconds' => 0,
                'apps' => [],
                'websites' => [],
            ];
        }

        $attendance = $db->prepare(
            'SELECT a.work_date, MIN(a.opened_at) AS first_in, MAX(a.closed_at) AS last_out,
                    SUM(CASE WHEN attendance_employee.attendance_mode="flexible"
                        THEN COALESCE(FLOOR(fad.active_seconds / 60), a.calculated_work_minutes, 0)
                        ELSE COALESCE(a.calculated_work_minutes, GREATEST(0, TIMESTAMPDIFF(MINUTE, a.opened_at, COALESCE(a.closed_at, UTC_TIMESTAMP())))) END) * 60 AS work_seconds,
                    SUM(COALESCE(a.calculated_break_minutes, 0)) * 60 AS break_seconds,
                    MAX(CASE WHEN a.opened_at > DATE_ADD(a.scheduled_start_at, INTERVAL COALESCE(s.grace_period_minutes, 0) MINUTE)
                        THEN TIMESTAMPDIFF(MINUTE, a.scheduled_start_at, a.opened_at) ELSE 0 END) AS late_minutes
             FROM attendance a
             JOIN employees attendance_employee ON attendance_employee.id=a.employee_id
             LEFT JOIN flexible_attendance_days fad ON fad.attendance_id=a.id
             LEFT JOIN shifts s ON s.id = a.shift_id
             WHERE a.employee_id = :employee_id AND a.work_date >= :work_start AND a.work_date <= :work_end
               AND a.deleted_at IS NULL
             GROUP BY a.work_date'
        );
        $attendance->execute([
            'employee_id' => $employeeId,
            'work_start' => $localStart->format('Y-m-d'),
            'work_end' => $localEnd->format('Y-m-d'),
        ]);
        foreach ($attendance->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = (string) $row['work_date'];
            if (isset($days[$date])) {
                foreach (['first_in', 'last_out', 'work_seconds', 'break_seconds', 'late_minutes'] as $key) {
                    $days[$date][$key] = $row[$key];
                }
            }
        }

        $idleStatement = $db->prepare(
            'SELECT we.occurred_at, we.idle_seconds, (SELECT MAX(ia.opened_at) FROM attendance ia
                 WHERE ia.employee_id=we.employee_id AND ia.deleted_at IS NULL
                   AND ia.opened_at <= we.occurred_at
                   AND we.occurred_at <= COALESCE(ia.closed_at, UTC_TIMESTAMP())) AS attendance_start
             FROM windows_events we
             JOIN devices d ON d.id = we.device_id
             JOIN windows_event_types wet ON wet.id = we.event_type_id
             WHERE d.company_id = :company_id AND we.employee_id = :employee_id
               AND wet.code = "idle" AND we.occurred_at >= :start_at AND we.occurred_at < :end_at
               AND EXISTS (
                   SELECT 1 FROM attendance idle_attendance
                   WHERE idle_attendance.employee_id = we.employee_id
                     AND idle_attendance.deleted_at IS NULL
                     AND we.occurred_at >= idle_attendance.opened_at
                     AND we.occurred_at <= COALESCE(idle_attendance.closed_at, UTC_TIMESTAMP())
               )
             ORDER BY we.occurred_at, we.id'
        );
        $idleStatement->execute($parameters);
        $this->addIdleEpisodes($days, $idleStatement->fetchAll(PDO::FETCH_ASSOC), $timezone);

        $classificationSql = $this->classificationSql();
        $usageStatement = $db->prepare(
            'SELECT aue.started_at, aue.app_name, aue.website_domain, aue.duration_seconds,
                    (' . $classificationSql . ') AS classification
             FROM activity_usage_events aue
             WHERE aue.company_id = :company_id AND aue.employee_id = :employee_id
               AND aue.started_at >= :start_at AND aue.started_at < :end_at
             ORDER BY aue.started_at, aue.id'
        );
        $usageStatement->execute($parameters);
        $this->addUsage($days, $usageStatement->fetchAll(PDO::FETCH_ASSOC), $timezone);

        $events = array_merge(
            $this->attendanceEvents($employeeId, $utcStart, $utcEnd),
            $this->windowsEvents($companyId, $employeeId, $utcStart, $utcEnd),
            $this->alerts($companyId, $employeeId, $utcStart, $utcEnd)
        );
        usort($events, static fn (array $a, array $b): int => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));

        $totals = [
            'tracked_seconds' => 0,
            'idle_seconds' => 0,
            'idle_episodes' => 0,
            'app_count' => 0,
            'website_count' => 0,
            'significant_events' => count($events),
            'late_days' => 0,
        ];
        $allApps = [];
        $allWebsites = [];
        foreach ($days as &$day) {
            $day['apps'] = $this->rankSources($day['apps']);
            $day['websites'] = $this->rankSources($day['websites']);
            $totals['tracked_seconds'] += (int) $day['tracked_seconds'];
            $totals['idle_seconds'] += (int) $day['idle_seconds'];
            $totals['idle_episodes'] += (int) $day['idle_episodes'];
            $totals['late_days'] += (int) $day['late_minutes'] > 0 ? 1 : 0;
            foreach ($day['apps'] as $source) $allApps[$source['name']] = true;
            foreach ($day['websites'] as $source) $allWebsites[$source['name']] = true;
        }
        unset($day);
        $totals['app_count'] = count($allApps);
        $totals['website_count'] = count($allWebsites);

        return [
            'employee' => $employee,
            'start' => $localStart->format('Y-m-d'),
            'end' => $localEnd->format('Y-m-d'),
            'range_label' => $localStart == $localEnd ? $localStart->format('d M Y') : $localStart->format('d M') . ' – ' . $localEnd->format('d M Y'),
            'days' => array_values($days),
            'events' => $events,
            'totals' => $totals,
        ];
    }

    /** @return array{DateTimeImmutable,DateTimeImmutable,DateTimeImmutable,DateTimeImmutable} */
    private function range(?string $startValue, ?string $endValue, DateTimeZone $timezone): array
    {
        $today = new DateTimeImmutable('today', $timezone);
        $defaultStart = $today->sub(new DateInterval('P6D'));
        $start = is_string($startValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startValue) ? new DateTimeImmutable($startValue, $timezone) : $defaultStart;
        $end = is_string($endValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endValue) ? new DateTimeImmutable($endValue, $timezone) : $today;
        if ($start > $end) [$start, $end] = [$end, $start];
        $earliest = $end->sub(new DateInterval('P30D'));
        if ($start < $earliest) $start = $earliest;
        $utc = new DateTimeZone('UTC');
        return [$start, $end, $start->setTime(0, 0)->setTimezone($utc), $end->modify('+1 day')->setTime(0, 0)->setTimezone($utc)];
    }

    private function classificationSql(): string
    {
        return 'COALESCE((SELECT pcr.category FROM productivity_classification_rules pcr
            WHERE pcr.company_id = aue.company_id
              AND pcr.match_target = CASE WHEN aue.website_domain IS NOT NULL AND aue.website_domain <> "" THEN "website" ELSE "app" END
              AND LOWER(CASE WHEN aue.website_domain IS NOT NULL AND aue.website_domain <> "" THEN aue.website_domain ELSE aue.app_name END)
                  LIKE CONCAT("%", LOWER(pcr.pattern), "%")
            ORDER BY CHAR_LENGTH(pcr.pattern) DESC, pcr.id DESC LIMIT 1), "unclassified")';
    }

    /** @param array<string,array<string,mixed>> $days @param list<array<string,mixed>> $rows */
    private function addIdleEpisodes(array &$days, array $rows, DateTimeZone $timezone): void
    {
        $samples = [];
        foreach ($rows as $row) {
            $time = (new DateTimeImmutable($row['occurred_at'], new DateTimeZone('UTC')))->setTimezone($timezone);
            $samples[$time->format('Y-m-d')][] = $row;
        }
        foreach ($samples as $date => $events) {
            if (!isset($days[$date])) continue;
            foreach (IdleIntervals::summarize($events, $timezone) as $key => $value) $days[$date][$key] = $value;
        }
    }

    /** @param array<string,array<string,mixed>> $days @param list<array<string,mixed>> $rows */
    private function addUsage(array &$days, array $rows, DateTimeZone $timezone): void
    {
        foreach ($rows as $row) {
            $date = (new DateTimeImmutable((string) $row['started_at'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
            if (!isset($days[$date])) continue;
            $seconds = max(0, (int) $row['duration_seconds']);
            $category = in_array($row['classification'], ['productive', 'neutral', 'distracting'], true) ? $row['classification'] : 'unclassified';
            $days[$date]['tracked_seconds'] += $seconds;
            $days[$date][$category . '_seconds'] += $seconds;
            $target = $row['website_domain'] ? 'websites' : 'apps';
            $name = (string) ($row['website_domain'] ?: $row['app_name']);
            if (!isset($days[$date][$target][$name])) {
                $days[$date][$target][$name] = ['name' => $name, 'duration_seconds' => 0, 'classification' => $category];
            }
            $days[$date][$target][$name]['duration_seconds'] += $seconds;
        }
    }

    /** @param array<string,array<string,mixed>> $sources @return list<array<string,mixed>> */
    private function rankSources(array $sources): array
    {
        $rows = array_values($sources);
        usort($rows, static fn (array $a, array $b): int => (int) $b['duration_seconds'] <=> (int) $a['duration_seconds']);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function attendanceEvents(int $employeeId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ae.occurred_at, et.name AS event_name, et.code AS event_code, ae.source,
                    ae.computer_name AS device_name, ae.correction_reason AS detail
             FROM attendance_events ae JOIN attendance_event_types et ON et.id = ae.event_type_id
             WHERE ae.employee_id = :employee_id AND ae.occurred_at >= :start_at AND ae.occurred_at < :end_at'
        );
        $statement->execute(['employee_id' => $employeeId, 'start_at' => $start->format('Y-m-d H:i:s'), 'end_at' => $end->format('Y-m-d H:i:s')]);
        return array_map(static fn (array $row): array => $row + ['event_group' => 'Attendance', 'severity' => ''], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function windowsEvents(int $companyId, int $employeeId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = Database::connection()->prepare(
            'SELECT we.occurred_at, wet.name AS event_name, wet.code AS event_code, "Windows agent" AS source,
                    COALESCE(d.hostname, d.name) AS device_name,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(we.metadata, "$.reason")), "") AS detail
             FROM windows_events we
             JOIN windows_event_types wet ON wet.id = we.event_type_id
             JOIN devices d ON d.id = we.device_id
             WHERE d.company_id = :company_id AND we.employee_id = :employee_id
               AND wet.code IN ("login", "logout", "lock", "unlock", "sleep", "wake",
                                "shutdown", "restart", "agent_exit", "agent_uninstall")
               AND we.occurred_at >= :start_at AND we.occurred_at < :end_at'
        );
        $statement->execute(['company_id' => $companyId, 'employee_id' => $employeeId, 'start_at' => $start->format('Y-m-d H:i:s'), 'end_at' => $end->format('Y-m-d H:i:s')]);
        return array_map(static fn (array $row): array => $row + ['event_group' => 'Windows', 'severity' => ''], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function alerts(int $companyId, int $employeeId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = Database::connection()->prepare(
            'SELECT aa.created_at AS occurred_at, REPLACE(aa.alert_type, "_", " ") AS event_name,
                    aa.alert_type AS event_code, "Anomaly scanner" AS source, "" AS device_name,
                    aa.description AS detail, aa.severity
             FROM anomaly_alerts aa
             WHERE aa.company_id = :company_id AND aa.employee_id = :employee_id
               AND aa.created_at >= :start_at AND aa.created_at < :end_at'
        );
        $statement->execute(['company_id' => $companyId, 'employee_id' => $employeeId, 'start_at' => $start->format('Y-m-d H:i:s'), 'end_at' => $end->format('Y-m-d H:i:s')]);
        return array_map(static fn (array $row): array => $row + ['event_group' => 'Alert'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
