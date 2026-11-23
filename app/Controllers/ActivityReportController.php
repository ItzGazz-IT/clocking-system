<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\ProductivityExportPdfService;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ActivityReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $employeeFilter = $this->positiveInteger($request->input('employee_id'));
        $departmentFilter = $this->positiveInteger($request->input('department_id'));

        return $this->view('reports/activity', $this->reportData(
            $request,
            $user->can('attendance.view.all') ? $employeeFilter : $user->employeeId,
            $user->can('attendance.view.all') ? $departmentFilter : null
        ) + [
            'user' => $user,
            'selectedEmployeeId' => $user->can('attendance.view.all') ? $employeeFilter : $user->employeeId,
            'selectedDepartmentId' => $user->can('attendance.view.all') ? $departmentFilter : null,
            'canManageClassifications' => $user->can('settings.manage'),
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function mine(Request $request): Response
    {
        $user = Auth::user();
        if (!$user->employeeId) {
            return Response::redirect(app_url('/dashboard'));
        }

        return $this->view('reports/my-productivity', $this->reportData($request, $user->employeeId, null) + [
            'user' => $user,
        ]);
    }

    public function productivityPdf(Request $request): Response
    {
        $user = Auth::user();
        $this->ensureDefaultClassificationRules($user->companyId);
        $employeeFilters = $this->positiveIntegers($request->input('employee_ids', []));
        $legacyEmployee = $this->positiveInteger($request->input('employee_id'));
        if ($employeeFilters === [] && $legacyEmployee !== null) {
            $employeeFilters = [$legacyEmployee];
        }
        $departmentFilter = $this->positiveInteger($request->input('department_id'));
        $timezone = new DateTimeZone((string) config('app.timezone', 'Africa/Johannesburg'));
        [$localStart, $localEnd, $start, $end] = $this->requestedRange($request, $timezone);

        $classificationSql = $this->classificationSql();
        $where = 'WHERE aue.company_id = :company_id AND aue.started_at >= :start_at AND aue.started_at < :end_at';
        $parameters = [
            'company_id' => $user->companyId,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ];
        if ($employeeFilters !== []) {
            $placeholders = [];
            foreach ($employeeFilters as $index => $employeeId) {
                $key = 'export_employee_' . $index;
                $placeholders[] = ':' . $key;
                $parameters[$key] = $employeeId;
            }
            $where .= ' AND aue.employee_id IN (' . implode(', ', $placeholders) . ')';
        }
        if ($departmentFilter !== null) {
            $where .= ' AND e.department_id = :department_id';
            $parameters['department_id'] = $departmentFilter;
        }

        $employeeRows = $this->fetchAll(
            'SELECT aue.employee_id, e.employee_number,
                    CONCAT(e.first_name, " ", e.last_name) employee_name,
                    d.name department_name,
                    SUM(aue.duration_seconds) duration_seconds,
                    SUM(CASE WHEN (' . $classificationSql . ') = "productive" THEN aue.duration_seconds ELSE 0 END) productive_seconds,
                    SUM(CASE WHEN (' . $classificationSql . ') = "neutral" THEN aue.duration_seconds ELSE 0 END) neutral_seconds,
                    SUM(CASE WHEN (' . $classificationSql . ') = "distracting" THEN aue.duration_seconds ELSE 0 END) distracting_seconds,
                    SUM(CASE WHEN (' . $classificationSql . ') = "unclassified" THEN aue.duration_seconds ELSE 0 END) unclassified_seconds
             FROM activity_usage_events aue
             LEFT JOIN employees e ON e.id = aue.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             ' . $where . '
             GROUP BY aue.employee_id, e.employee_number, employee_name, d.name
             ORDER BY employee_name',
            $parameters
        );
        $sourceRows = $this->fetchAll(
            'SELECT aue.employee_id, e.employee_number,
                    CONCAT(e.first_name, " ", e.last_name) employee_name,
                    d.name department_name,
                    CASE WHEN aue.website_domain IS NOT NULL AND aue.website_domain <> "" THEN "Website" ELSE "Application" END record_type,
                    CASE WHEN aue.website_domain IS NOT NULL AND aue.website_domain <> "" THEN aue.website_domain ELSE aue.app_name END source_name,
                    (' . $classificationSql . ') classification,
                    SUM(aue.duration_seconds) duration_seconds
             FROM activity_usage_events aue
             LEFT JOIN employees e ON e.id = aue.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             ' . $where . '
             GROUP BY aue.employee_id, e.employee_number, employee_name, d.name, record_type, source_name, classification
             ORDER BY employee_name, record_type, duration_seconds DESC',
            $parameters
        );

        $company = Database::connection()->prepare('SELECT name FROM companies WHERE id = :id LIMIT 1');
        $company->execute(['id' => $user->companyId]);
        $pdf = (new ProductivityExportPdfService())->render([
            'company' => (string) ($company->fetchColumn() ?: config('app.name')),
            'start' => $localStart->format('Y-m-d'),
            'end' => $localEnd->format('Y-m-d'),
            'range_label' => $localStart == $localEnd
                ? $localStart->format('d M Y')
                : $localStart->format('d M Y') . ' to ' . $localEnd->format('d M Y'),
            'employees' => $employeeRows,
            'sources' => $sourceRows,
        ]);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="productivity-' . $localStart->format('Y-m-d') . '-to-' . $localEnd->format('Y-m-d') . '.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function storeClassification(Request $request): Response
    {
        $user = Auth::user();
        $target = strtolower(trim((string) $request->input('match_target')));
        $category = strtolower(trim((string) $request->input('category')));
        $pattern = strtolower(trim((string) $request->input('pattern')));

        if (!in_array($target, ['app', 'website'], true)
            || !in_array($category, ['productive', 'neutral', 'distracting'], true)
            || $pattern === '' || strlen($pattern) > 190) {
            Session::flash('error', 'Choose a valid source, category, and matching text.');
            return Response::redirect($this->returnUrl($request));
        }

        Database::connection()->prepare(
            'INSERT INTO productivity_classification_rules
                (company_id, match_target, pattern, category, created_by_user_id)
             VALUES (:company_id, :match_target, :pattern, :category, :user_id)
             ON DUPLICATE KEY UPDATE category = VALUES(category), created_by_user_id = VALUES(created_by_user_id)'
        )->execute([
            'company_id' => $user->companyId,
            'match_target' => $target,
            'pattern' => $pattern,
            'category' => $category,
            'user_id' => $user->id,
        ]);

        Session::flash('success', 'Productivity classification saved.');
        return Response::redirect($this->returnUrl($request));
    }

    public function deleteClassification(Request $request, string $id): Response
    {
        $user = Auth::user();
        $statement = Database::connection()->prepare(
            'DELETE FROM productivity_classification_rules WHERE id = :id AND company_id = :company_id'
        );
        $statement->execute(['id' => (int) $id, 'company_id' => $user->companyId]);
        Session::flash('success', 'Productivity classification removed.');

        return Response::redirect($this->returnUrl($request));
    }

    /** @return array<string, mixed> */
    private function reportData(Request $request, ?int $employeeFilter, ?int $departmentFilter): array
    {
        $user = Auth::user();
        $db = Database::connection();
        $this->ensureDefaultClassificationRules($user->companyId);

        $timezone = new DateTimeZone((string) config('app.timezone', 'Africa/Johannesburg'));
        [$localStart, $localEnd, $start, $end] = $this->requestedRange($request, $timezone);
        $classificationFilter = strtolower(trim((string) $request->input('classification', '')));
        if (!in_array($classificationFilter, ['productive', 'neutral', 'distracting', 'unclassified'], true)) {
            $classificationFilter = '';
        }
        $sourceFilter = substr(trim((string) $request->input('source', '')), 0, 100);
        $minimumMinutes = max(0, min(1440, (int) $request->input('minimum_minutes', 0)));

        $classificationSql = $this->classificationSql();
        $where = 'WHERE aue.company_id = :company_id AND aue.started_at >= :start_at AND aue.started_at < :end_at';
        $parameters = [
            'company_id' => $user->companyId,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ];
        if ($employeeFilter !== null) {
            $where .= ' AND aue.employee_id = :employee_id';
            $parameters['employee_id'] = $employeeFilter;
        }
        if ($departmentFilter !== null) {
            $where .= ' AND e.department_id = :department_id';
            $parameters['department_id'] = $departmentFilter;
        }
        if ($sourceFilter !== '') {
            $where .= ' AND LOWER(CONCAT(aue.app_name, " ", COALESCE(aue.website_domain, ""))) LIKE :source';
            $parameters['source'] = '%' . strtolower($sourceFilter) . '%';
        }
        if ($classificationFilter !== '') {
            $where .= ' AND (' . $classificationSql . ') = :classification';
            $parameters['classification'] = $classificationFilter;
        }

        $employees = $db->prepare(
            'SELECT id, employee_number, first_name, last_name, department_id
             FROM employees
             WHERE company_id = :company_id AND deleted_at IS NULL
             ORDER BY first_name, last_name'
        );
        $employees->execute(['company_id' => $user->companyId]);

        $departments = $db->prepare(
            'SELECT id, name FROM departments
             WHERE company_id = :company_id AND deleted_at IS NULL AND is_active = 1 ORDER BY name'
        );
        $departments->execute(['company_id' => $user->companyId]);

        $summary = $db->prepare(
            'SELECT
                COALESCE(SUM(aue.duration_seconds), 0) AS tracked_seconds,
                COALESCE(SUM(CASE WHEN (' . $classificationSql . ') = "productive" THEN aue.duration_seconds ELSE 0 END), 0) AS focus_seconds,
                COALESCE(SUM(CASE WHEN (' . $classificationSql . ') = "neutral" THEN aue.duration_seconds ELSE 0 END), 0) AS neutral_seconds,
                COALESCE(SUM(CASE WHEN (' . $classificationSql . ') = "distracting" THEN aue.duration_seconds ELSE 0 END), 0) AS risk_seconds,
                COALESCE(SUM(CASE WHEN (' . $classificationSql . ') = "unclassified" THEN aue.duration_seconds ELSE 0 END), 0) AS unclassified_seconds,
                COUNT(DISTINCT aue.employee_id) AS employees_seen,
                COUNT(DISTINCT aue.app_name) AS apps_used,
                COUNT(DISTINCT aue.website_domain) AS websites_used
             FROM activity_usage_events aue
             LEFT JOIN employees e ON e.id = aue.employee_id ' . $where
        );
        $summary->execute($parameters);
        $summaryRow = $summary->fetch() ?: [];

        $employeeSql =
            'SELECT aue.employee_id,
                    CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                    e.employee_number,
                    d.name AS department_name,
                    SUM(aue.duration_seconds) AS duration_seconds,
                    SUM(CASE WHEN (' . $classificationSql . ') = "productive" THEN aue.duration_seconds ELSE 0 END) AS focus_seconds,
                    SUM(CASE WHEN (' . $classificationSql . ') = "neutral" THEN aue.duration_seconds ELSE 0 END) AS neutral_seconds,
                    SUM(CASE WHEN (' . $classificationSql . ') = "distracting" THEN aue.duration_seconds ELSE 0 END) AS risk_seconds,
                    SUM(CASE WHEN (' . $classificationSql . ') = "unclassified" THEN aue.duration_seconds ELSE 0 END) AS unclassified_seconds,
                    COUNT(DISTINCT aue.app_name) AS app_count,
                    COUNT(DISTINCT aue.website_domain) AS website_count,
                    MAX(aue.ended_at) AS last_activity_at
             FROM activity_usage_events aue
             LEFT JOIN employees e ON e.id = aue.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             ' . $where . '
             GROUP BY aue.employee_id, employee_name, e.employee_number, d.name';
        $employeeParameters = $parameters;
        if ($minimumMinutes > 0) {
            $employeeSql .= ' HAVING SUM(aue.duration_seconds) >= :minimum_seconds';
            $employeeParameters['minimum_seconds'] = $minimumMinutes * 60;
        }
        $employeeSql .= ' ORDER BY duration_seconds DESC LIMIT 50';
        $byEmployee = $this->fetchAll($employeeSql, $employeeParameters);

        $topApps = $this->fetchAll(
            'SELECT aue.app_name, (' . $classificationSql . ') AS classification,
                    SUM(aue.duration_seconds) AS duration_seconds, COUNT(DISTINCT aue.employee_id) AS employee_count
             FROM activity_usage_events aue LEFT JOIN employees e ON e.id = aue.employee_id
             ' . $where . ' AND aue.website_domain IS NULL
             GROUP BY aue.app_name, classification ORDER BY duration_seconds DESC LIMIT 10',
            $parameters
        );

        $topWebsites = $this->fetchAll(
            'SELECT aue.website_domain, (' . $classificationSql . ') AS classification,
                    SUM(aue.duration_seconds) AS duration_seconds, COUNT(DISTINCT aue.employee_id) AS employee_count
             FROM activity_usage_events aue LEFT JOIN employees e ON e.id = aue.employee_id
             ' . $where . ' AND aue.website_domain IS NOT NULL
             GROUP BY aue.website_domain, classification ORDER BY duration_seconds DESC LIMIT 10',
            $parameters
        );

        $riskSources = $this->fetchAll(
            'SELECT aue.employee_id, CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                    aue.app_name, aue.website_domain, SUM(aue.duration_seconds) AS duration_seconds,
                    MIN(aue.started_at) AS first_seen_at, MAX(aue.ended_at) AS last_seen_at
             FROM activity_usage_events aue LEFT JOIN employees e ON e.id = aue.employee_id
             ' . $where . ' AND (' . $classificationSql . ') = "distracting"
             GROUP BY aue.employee_id, employee_name, aue.app_name, aue.website_domain
             ORDER BY duration_seconds DESC LIMIT 20',
            $parameters
        );

        $hourlyRows = $this->fetchAll(
            'SELECT aue.started_at, aue.duration_seconds, (' . $classificationSql . ') AS classification
             FROM activity_usage_events aue LEFT JOIN employees e ON e.id = aue.employee_id
             ' . $where . ' ORDER BY aue.started_at',
            $parameters
        );
        $hourlyTrend = $this->hourlyTrend($hourlyRows, $timezone);

        $attendanceWhere = 'WHERE e.company_id = :attendance_company_id
            AND a.work_date >= :work_start AND a.work_date <= :work_end AND a.deleted_at IS NULL';
        $attendanceParameters = [
            'attendance_company_id' => $user->companyId,
            'work_start' => $localStart->format('Y-m-d'),
            'work_end' => $localEnd->format('Y-m-d'),
        ];
        if ($employeeFilter !== null) {
            $attendanceWhere .= ' AND a.employee_id = :attendance_employee_id';
            $attendanceParameters['attendance_employee_id'] = $employeeFilter;
        }
        if ($departmentFilter !== null) {
            $attendanceWhere .= ' AND e.department_id = :attendance_department_id';
            $attendanceParameters['attendance_department_id'] = $departmentFilter;
        }
        $attendance = $db->prepare(
            'SELECT COALESCE(SUM(COALESCE(
                    a.calculated_work_minutes * 60,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, a.opened_at, COALESCE(a.closed_at, UTC_TIMESTAMP()))),
                    0
                )), 0) AS clocked_seconds
             FROM attendance a JOIN employees e ON e.id = a.employee_id ' . $attendanceWhere
        );
        $attendance->execute($attendanceParameters);
        $clockedSeconds = (int) $attendance->fetchColumn();
        $trackedSeconds = (int) ($summaryRow['tracked_seconds'] ?? 0);
        $coveragePercent = $clockedSeconds > 0 ? min(100, (int) round(($trackedSeconds / $clockedSeconds) * 100)) : 0;
        $summaryRow['clocked_seconds'] = $clockedSeconds;
        $summaryRow['coverage_percent'] = $coveragePercent;
        $summaryRow['untracked_seconds'] = max(0, $clockedSeconds - $trackedSeconds);

        $byEmployee = $this->withEmployeeScores($byEmployee);
        $dailyIdle = $this->dailyIdleRows(
            $user->companyId,
            $employeeFilter,
            $departmentFilter,
            $localStart,
            $localEnd,
            $start,
            $end,
            $timezone
        );
        $topApps = $this->withShare($topApps);
        $topWebsites = $this->withShare($topWebsites);

        $rules = $db->prepare(
            'SELECT id, match_target, pattern, category
             FROM productivity_classification_rules
             WHERE company_id = :company_id
             ORDER BY FIELD(category, "productive", "neutral", "distracting"), match_target, pattern'
        );
        $rules->execute(['company_id' => $user->companyId]);

        return [
            'date' => $localEnd->format('Y-m-d'),
            'startDate' => $localStart->format('Y-m-d'),
            'endDate' => $localEnd->format('Y-m-d'),
            'rangeLabel' => $localStart == $localEnd
                ? $localStart->format('l, d M Y')
                : $localStart->format('d M') . ' – ' . $localEnd->format('d M Y'),
            'employees' => $employees->fetchAll(),
            'departments' => $departments->fetchAll(),
            'summary' => $summaryRow,
            'byEmployee' => $byEmployee,
            'dailyIdle' => $dailyIdle,
            'topApps' => $topApps,
            'topWebsites' => $topWebsites,
            'riskSources' => $riskSources,
            'hourlyTrend' => $hourlyTrend,
            'classificationRules' => $rules->fetchAll(),
            'selectedClassification' => $classificationFilter,
            'sourceFilter' => $sourceFilter,
            'minimumMinutes' => $minimumMinutes,
            'insights' => $this->buildInsights($summaryRow, $byEmployee, $riskSources, $hourlyTrend),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function dailyIdleRows(
        int $companyId,
        ?int $employeeFilter,
        ?int $departmentFilter,
        DateTimeImmutable $localStart,
        DateTimeImmutable $localEnd,
        DateTimeImmutable $utcStart,
        DateTimeImmutable $utcEnd,
        DateTimeZone $timezone
    ): array {
        $employeeWhere = 'WHERE e.company_id = :company_id AND e.deleted_at IS NULL';
        $employeeParameters = ['company_id' => $companyId];
        if ($employeeFilter !== null) {
            $employeeWhere .= ' AND e.id = :employee_id';
            $employeeParameters['employee_id'] = $employeeFilter;
        }
        if ($departmentFilter !== null) {
            $employeeWhere .= ' AND e.department_id = :department_id';
            $employeeParameters['department_id'] = $departmentFilter;
        }

        $employees = $this->fetchAll(
            'SELECT e.id, e.employee_number, CONCAT(e.first_name, " ", e.last_name) employee_name,
                    d.name department_name
             FROM employees e LEFT JOIN departments d ON d.id = e.department_id
             ' . $employeeWhere . ' ORDER BY employee_name',
            $employeeParameters
        );

        $rows = [];
        $period = new \DatePeriod($localStart, new DateInterval('P1D'), $localEnd->modify('+1 day'));
        foreach ($period as $day) {
            foreach ($employees as $employee) {
                $key = $employee['id'] . '|' . $day->format('Y-m-d');
                $rows[$key] = $employee + [
                    'date' => $day->format('Y-m-d'),
                    'idle_seconds' => 0,
                    'longest_idle_seconds' => 0,
                    'idle_episodes' => 0,
                    'idle_samples' => 0,
                ];
            }
        }

        $eventWhere = 'WHERE d.company_id = :company_id AND wet.code = "idle"
            AND we.occurred_at >= :start_at AND we.occurred_at < :end_at
            AND EXISTS (
                SELECT 1 FROM attendance idle_attendance
                WHERE idle_attendance.employee_id = we.employee_id
                  AND idle_attendance.deleted_at IS NULL
                  AND we.occurred_at >= idle_attendance.opened_at
                  AND we.occurred_at <= COALESCE(idle_attendance.closed_at, UTC_TIMESTAMP())
            )';
        $eventParameters = [
            'company_id' => $companyId,
            'start_at' => $utcStart->format('Y-m-d H:i:s'),
            'end_at' => $utcEnd->format('Y-m-d H:i:s'),
        ];
        if ($employeeFilter !== null) {
            $eventWhere .= ' AND we.employee_id = :employee_id';
            $eventParameters['employee_id'] = $employeeFilter;
        }
        if ($departmentFilter !== null) {
            $eventWhere .= ' AND e.department_id = :department_id';
            $eventParameters['department_id'] = $departmentFilter;
        }
        $events = $this->fetchAll(
            'SELECT we.id, we.employee_id, we.occurred_at, we.idle_seconds, (SELECT MAX(ia.opened_at) FROM attendance ia
                 WHERE ia.employee_id=we.employee_id AND ia.deleted_at IS NULL
                   AND ia.opened_at <= we.occurred_at
                   AND we.occurred_at <= COALESCE(ia.closed_at, UTC_TIMESTAMP())) AS attendance_start
             FROM windows_events we
             JOIN devices d ON d.id = we.device_id
             JOIN windows_event_types wet ON wet.id = we.event_type_id
             JOIN employees e ON e.id = we.employee_id
             ' . $eventWhere . ' ORDER BY we.employee_id, we.occurred_at, we.id',
            $eventParameters
        );

        $samples = [];
        foreach ($events as $event) {
            $time = (new DateTimeImmutable($event['occurred_at'], new DateTimeZone('UTC')))->setTimezone($timezone);
            $key = $event['employee_id'] . '|' . $time->format('Y-m-d');
            $samples[$key][] = $event;
        }
        foreach ($samples as $key => $events) {
            if (!isset($rows[$key])) continue;
            foreach (\App\Services\IdleIntervals::summarize($events, $timezone) as $field => $value) $rows[$key][$field] = $value;
        }

        $result = array_values($rows);
        usort($result, static fn (array $a, array $b): int =>
            strcmp((string) $b['date'], (string) $a['date'])
            ?: ((int) $b['idle_seconds'] <=> (int) $a['idle_seconds'])
            ?: strcmp((string) $a['employee_name'], (string) $b['employee_name'])
        );
        return $result;
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable, DateTimeImmutable, DateTimeImmutable} */
    private function requestedRange(Request $request, DateTimeZone $timezone): array
    {
        $legacyDate = (string) $request->input('date', '');
        $startValue = (string) $request->input('start_date', $legacyDate);
        $endValue = (string) $request->input('end_date', $legacyDate);
        $today = new DateTimeImmutable('today', $timezone);
        $localStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startValue) ? new DateTimeImmutable($startValue, $timezone) : $today;
        $localEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endValue) ? new DateTimeImmutable($endValue, $timezone) : $localStart;
        if ($localStart > $localEnd) {
            [$localStart, $localEnd] = [$localEnd, $localStart];
        }
        $earliest = $localEnd->sub(new DateInterval('P30D'));
        if ($localStart < $earliest) {
            $localStart = $earliest;
        }
        $utc = new DateTimeZone('UTC');
        $start = $localStart->setTime(0, 0)->setTimezone($utc);
        $end = $localEnd->modify('+1 day')->setTime(0, 0)->setTimezone($utc);

        return [$localStart, $localEnd, $start, $end];
    }

    private function classificationSql(): string
    {
        $source = 'LOWER(CASE WHEN aue.website_domain IS NOT NULL AND aue.website_domain <> "" THEN aue.website_domain ELSE aue.app_name END)';
        $target = 'CASE WHEN aue.website_domain IS NOT NULL AND aue.website_domain <> "" THEN "website" ELSE "app" END';

        return 'COALESCE((SELECT pcr.category FROM productivity_classification_rules pcr
            WHERE pcr.company_id = aue.company_id
              AND pcr.match_target = ' . $target . '
              AND ' . $source . ' LIKE CONCAT("%", LOWER(pcr.pattern), "%")
            ORDER BY CHAR_LENGTH(pcr.pattern) DESC, pcr.id DESC LIMIT 1), "unclassified")';
    }


    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql, array $parameters): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function withShare(array $rows): array
    {
        $total = array_sum(array_map(static fn (array $row): int => (int) $row['duration_seconds'], $rows));
        foreach ($rows as &$row) {
            $row['share'] = $total > 0 ? min(100, (int) round(((int) $row['duration_seconds'] / $total) * 100)) : 0;
        }
        return $rows;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function withEmployeeScores(array $rows): array
    {
        foreach ($rows as &$row) {
            $duration = max(0, (int) $row['duration_seconds']);
            $productive = max(0, (int) $row['focus_seconds']);
            $distracting = max(0, (int) $row['risk_seconds']);
            $classified = $productive + $distracting + max(0, (int) $row['neutral_seconds']);
            $scoreDenominator = $productive + $distracting;
            $row['productivity_score'] = $scoreDenominator > 0 ? min(100, (int) round(($productive / $scoreDenominator) * 100)) : 0;
            $row['classification_confidence'] = $duration > 0 ? min(100, (int) round(($classified / $duration) * 100)) : 0;
        }
        return $rows;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, int>> */
    private function hourlyTrend(array $rows, DateTimeZone $timezone): array
    {
        $byHour = [];
        $max = 0;
        foreach ($rows as $row) {
            $hour = (int) (new DateTimeImmutable((string) $row['started_at'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('G');
            $category = (string) $row['classification'];
            $duration = (int) $row['duration_seconds'];
            $byHour[$hour]['duration_seconds'] = ($byHour[$hour]['duration_seconds'] ?? 0) + $duration;
            $byHour[$hour][$category . '_seconds'] = ($byHour[$hour][$category . '_seconds'] ?? 0) + $duration;
            $max = max($max, $byHour[$hour]['duration_seconds']);
        }
        $trend = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $duration = $byHour[$hour]['duration_seconds'] ?? 0;
            $trend[] = [
                'hour' => $hour,
                'duration_seconds' => $duration,
                'productive_seconds' => $byHour[$hour]['productive_seconds'] ?? 0,
                'neutral_seconds' => $byHour[$hour]['neutral_seconds'] ?? 0,
                'distracting_seconds' => $byHour[$hour]['distracting_seconds'] ?? 0,
                'unclassified_seconds' => $byHour[$hour]['unclassified_seconds'] ?? 0,
                'height' => $duration > 0 && $max > 0 ? max(8, (int) round(($duration / $max) * 100)) : 0,
            ];
        }
        return $trend;
    }

    /** @return list<array{tone:string,title:string,detail:string}> */
    private function buildInsights(array $summary, array $employees, array $riskSources, array $hourly): array
    {
        $tracked = (int) ($summary['tracked_seconds'] ?? 0);
        if ($tracked === 0) {
            return [['tone' => 'neutral', 'title' => 'No activity captured', 'detail' => 'No foreground application or website activity matches this period and filter set.']];
        }
        $insights = [];
        $coverage = (int) ($summary['coverage_percent'] ?? 0);
        if ((int) ($summary['clocked_seconds'] ?? 0) > 0 && $coverage < 70) {
            $insights[] = ['tone' => 'warning', 'title' => 'Tracking coverage needs attention', 'detail' => $coverage . '% of clocked time has activity evidence. Check agent connectivity or untracked work.'];
        } elseif ($coverage >= 90) {
            $insights[] = ['tone' => 'positive', 'title' => 'Strong tracking coverage', 'detail' => $coverage . '% of clocked time is supported by activity evidence.'];
        }
        $classified = (int) ($summary['focus_seconds'] ?? 0) + (int) ($summary['neutral_seconds'] ?? 0) + (int) ($summary['risk_seconds'] ?? 0);
        $confidence = min(100, (int) round(($classified / max(1, $tracked)) * 100));
        if ($confidence < 70) {
            $insights[] = ['tone' => 'warning', 'title' => 'Score confidence is limited', 'detail' => (100 - $confidence) . '% of tracked activity is still unclassified. Classify the leading sources to improve the score.'];
        }
        if ($riskSources !== []) {
            $top = $riskSources[0];
            $insights[] = ['tone' => 'risk', 'title' => 'Largest distraction source', 'detail' => ($top['website_domain'] ?: $top['app_name']) . ' accounted for ' . $this->duration((int) $top['duration_seconds']) . '.'];
        }
        $bestHour = null;
        foreach ($hourly as $row) {
            if ((int) $row['productive_seconds'] > (int) ($bestHour['productive_seconds'] ?? 0)) {
                $bestHour = $row;
            }
        }
        if ($bestHour && (int) $bestHour['productive_seconds'] > 0) {
            $label = str_pad((string) $bestHour['hour'], 2, '0', STR_PAD_LEFT) . ':00';
            $insights[] = ['tone' => 'positive', 'title' => 'Strongest focus period', 'detail' => $label . ' recorded the most productive activity at ' . $this->duration((int) $bestHour['productive_seconds']) . '.'];
        }
        if (count($employees) > 1) {
            usort($employees, static fn (array $a, array $b): int => (int) $b['risk_seconds'] <=> (int) $a['risk_seconds']);
            if ((int) $employees[0]['risk_seconds'] > 0) {
                $insights[] = ['tone' => 'neutral', 'title' => 'Employee follow-up', 'detail' => $employees[0]['employee_name'] . ' has the most distracting time in this view (' . $this->duration((int) $employees[0]['risk_seconds']) . ').'];
            }
        }
        return array_slice($insights, 0, 4);
    }

    private function ensureDefaultClassificationRules(int $companyId): void
    {
        $db = Database::connection();
        $check = $db->prepare('SELECT COUNT(*) FROM productivity_classification_rules WHERE company_id = :company_id');
        $check->execute(['company_id' => $companyId]);
        if ((int) $check->fetchColumn() > 0) {
            return;
        }
        $defaults = [
            ['website', 'youtube', 'distracting'], ['website', 'facebook', 'distracting'], ['website', 'instagram', 'distracting'],
            ['website', 'tiktok', 'distracting'], ['website', 'netflix', 'distracting'], ['website', 'reddit', 'distracting'],
            ['app', 'steam', 'distracting'], ['app', 'spotify', 'distracting'], ['app', 'discord', 'distracting'],
            ['app', 'whatsapp', 'distracting'], ['app', 'microsoft word', 'productive'], ['app', 'microsoft excel', 'productive'],
            ['app', 'microsoft outlook', 'productive'], ['app', 'microsoft teams', 'productive'], ['app', 'visual studio', 'productive'],
            ['app', 'file explorer', 'neutral'], ['app', 'google chrome', 'neutral'], ['app', 'microsoft edge', 'neutral'],
        ];
        $insert = $db->prepare(
            'INSERT IGNORE INTO productivity_classification_rules (company_id, match_target, pattern, category)
             VALUES (:company_id, :target, :pattern, :category)'
        );
        foreach ($defaults as [$target, $pattern, $category]) {
            $insert->execute(['company_id' => $companyId, 'target' => $target, 'pattern' => $pattern, 'category' => $category]);
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $filtered === false ? null : (int) $filtered;
    }

    /** @return list<int> */
    private function positiveIntegers(mixed $values): array
    {
        if (!is_array($values)) {
            $values = [$values];
        }
        $ids = [];
        foreach ($values as $value) {
            $id = $this->positiveInteger($value);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        return array_slice(array_values($ids), 0, 200);
    }

    private function returnUrl(Request $request): string
    {
        $allowed = ['start_date', 'end_date', 'employee_id', 'department_id', 'classification', 'source', 'minimum_minutes'];
        $query = [];
        parse_str((string) $request->input('return_query', ''), $submitted);
        foreach ($allowed as $key) {
            if (isset($submitted[$key]) && is_scalar($submitted[$key])) {
                $query[$key] = (string) $submitted[$key];
            }
        }
        return app_url('/reports/activity' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    private function duration(int $seconds): string
    {
        $hours = intdiv(max(0, $seconds), 3600);
        $minutes = intdiv(max(0, $seconds) % 3600, 60);
        return $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
    }
}
