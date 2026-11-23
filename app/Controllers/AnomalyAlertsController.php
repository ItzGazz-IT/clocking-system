<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class AnomalyAlertsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $db = Database::connection();

        $timezone = new DateTimeZone((string) config('app.timezone', 'Africa/Johannesburg'));
        $today = new DateTimeImmutable('today', $timezone);
        $dateInput = trim((string) $request->input('date', ''));
        $selectedDay = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput, $timezone);
        if (!$selectedDay || $selectedDay->format('Y-m-d') !== $dateInput || $selectedDay > $today) {
            $selectedDay = $today;
        }
        $dayStart = $selectedDay->setTimezone(new DateTimeZone('UTC'));
        $dayEnd = $selectedDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $selectedDate = $selectedDay->format('Y-m-d');

        $statusInput = (string) $request->input('status', 'pending');
        $status = $statusInput === 'all' ? '' : ($statusInput ?: 'pending');
        $severity = $request->input('severity');
        $type = $request->input('type');
        $eventType = trim((string) $request->input('event_type', ''));
        $showEvidence = (string) $request->input('show_evidence', '') === '1' || $eventType !== '';
        $employeeId = filter_var($request->input('employee_id'), FILTER_VALIDATE_INT);
        $employeeFilter = $employeeId === false ? null : (int) $employeeId;
        $page = max(1, (int) ($request->input('page') ?: 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT aa.*, e.employee_number, CONCAT(e.first_name, " ", e.last_name) AS employee_name
                FROM anomaly_alerts aa
                JOIN employees e ON e.id = aa.employee_id
                WHERE aa.company_id = :company_id
                  AND aa.created_at >= :day_start AND aa.created_at < :day_end';
        $params = [
            'company_id' => $user->companyId,
            'day_start' => $dayStart->format('Y-m-d H:i:s'),
            'day_end' => $dayEnd->format('Y-m-d H:i:s'),
        ];

        if ($status) {
            $sql .= ' AND aa.status = :status';
            $params['status'] = $status;
        }

        if ($severity) {
            $sql .= ' AND aa.severity = :severity';
            $params['severity'] = $severity;
        }

        if ($type) {
            $sql .= ' AND aa.alert_type = :type';
            $params['type'] = $type;
        }

        if ($employeeFilter !== null) {
            $sql .= ' AND aa.employee_id = :employee_id';
            $params['employee_id'] = $employeeFilter;
        }

        // Get total count
        $countStmt = $db->prepare('SELECT COUNT(*) FROM (' . $sql . ') AS subquery');
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get alerts
        $sql .= ' ORDER BY CASE aa.severity WHEN "high" THEN 1 WHEN "medium" THEN 2 ELSE 3 END, aa.created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get available filters
        $typesStmt = $db->prepare(
            'SELECT DISTINCT alert_type FROM anomaly_alerts WHERE company_id = :company_id ORDER BY alert_type'
        );
        $typesStmt->execute(['company_id' => $user->companyId]);
        $types = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

        $employees = $db->prepare(
            'SELECT id, employee_number, first_name, last_name
             FROM employees
             WHERE company_id = :company_id AND deleted_at IS NULL
             ORDER BY first_name, last_name'
        );
        $employees->execute(['company_id' => $user->companyId]);

        $eventTypes = $db->query(
            'SELECT code, name FROM windows_event_types WHERE code NOT IN ("activity", "idle") ORDER BY name'
        )->fetchAll();
        $eventWhere = 'WHERE d.company_id = :company_id
            AND we.occurred_at >= :event_day_start AND we.occurred_at < :event_day_end
            AND wet.code <> "activity" AND wet.code <> "idle"';
        $eventParams = [
            'company_id' => $user->companyId,
            'event_day_start' => $dayStart->format('Y-m-d H:i:s'),
            'event_day_end' => $dayEnd->format('Y-m-d H:i:s'),
        ];
        if ($employeeFilter !== null) {
            $eventWhere .= ' AND we.employee_id = :event_employee_id';
            $eventParams['event_employee_id'] = $employeeFilter;
        }
        if ($eventType !== '') {
            $eventWhere .= ' AND wet.code = :event_type';
            $eventParams['event_type'] = $eventType;
        }

        $events = [];
        $activityEvidence = [];
        $flexibleEvidence = [];
        $trackingEvidence = [];
        if ($showEvidence) {
        $eventsStmt = $db->prepare(
            'SELECT we.id, we.occurred_at, we.received_at, we.idle_seconds, we.metadata,
                    wet.code, wet.name AS event_name,
                    d.name AS device_name, d.hostname,
                    CONCAT(e.first_name, " ", e.last_name) AS employee_name
             FROM windows_events we
             JOIN windows_event_types wet ON wet.id = we.event_type_id
             JOIN devices d ON d.id = we.device_id
             LEFT JOIN employees e ON e.id = we.employee_id
             ' . $eventWhere . '
             ORDER BY we.occurred_at DESC, we.id DESC
             LIMIT 100'
        );
        $eventsStmt->execute($eventParams);
        $events = $eventsStmt->fetchAll();

        // Routine heartbeats are intentionally hidden, but their daily aggregate is
        // useful evidence that the automatic agent was working.
        if ($eventType === '') {
            $activitySql = 'SELECT aue.employee_id, CONCAT(e.first_name, " ", e.last_name) employee_name,
                    COUNT(*) slice_count, SUM(aue.duration_seconds) tracked_seconds,
                    COUNT(DISTINCT aue.app_name) app_count,
                    COUNT(DISTINCT NULLIF(aue.website_domain, "")) website_count,
                    MIN(aue.started_at) first_activity_at, MAX(aue.ended_at) last_activity_at
                FROM activity_usage_events aue JOIN employees e ON e.id=aue.employee_id
                WHERE aue.company_id=:company_id AND aue.started_at>=:day_start AND aue.started_at<:day_end';
            $activityParams = ['company_id'=>$user->companyId, 'day_start'=>$dayStart->format('Y-m-d H:i:s'), 'day_end'=>$dayEnd->format('Y-m-d H:i:s')];
            if ($employeeFilter !== null) { $activitySql .= ' AND aue.employee_id=:employee_id'; $activityParams['employee_id']=$employeeFilter; }
            $activitySql .= ' GROUP BY aue.employee_id,e.first_name,e.last_name ORDER BY employee_name';
            $activityStmt = $db->prepare($activitySql); $activityStmt->execute($activityParams); $activityEvidence = $activityStmt->fetchAll();

            $flexSql = 'SELECT fad.employee_id, CONCAT(e.first_name, " ", e.last_name) employee_name,
                    fad.active_seconds, fad.inactive_seconds, fad.target_minutes, fad.overtime_minutes,
                    fad.overtime_status, a.opened_at, a.closed_at
                FROM flexible_attendance_days fad JOIN employees e ON e.id=fad.employee_id
                JOIN attendance a ON a.id=fad.attendance_id
                WHERE fad.company_id=:company_id AND fad.work_date=:work_date';
            $flexParams = ['company_id'=>$user->companyId, 'work_date'=>$selectedDate];
            if ($employeeFilter !== null) { $flexSql .= ' AND fad.employee_id=:employee_id'; $flexParams['employee_id']=$employeeFilter; }
            $flexSql .= ' ORDER BY employee_name';
            $flexStmt = $db->prepare($flexSql); $flexStmt->execute($flexParams); $flexibleEvidence = $flexStmt->fetchAll();

            foreach ($flexibleEvidence as $item) {
                $trackingEvidence[(int) $item['employee_id']] = ['employee_name'=>$item['employee_name'], 'attendance'=>$item, 'activity'=>null];
            }
            foreach ($activityEvidence as $item) {
                $id = (int) $item['employee_id'];
                $trackingEvidence[$id] ??= ['employee_name'=>$item['employee_name'], 'attendance'=>null, 'activity'=>null];
                $trackingEvidence[$id]['activity'] = $item;
            }
            $trackingEvidence = array_values($trackingEvidence);
        }
        }

        $latestIdleWhere = 'WHERE d.company_id = :company_id AND wet.code = "idle"
            AND we.occurred_at >= :day_start AND we.occurred_at < :day_end';
        $latestIdleParams = [
            'company_id' => $user->companyId,
            'day_start' => $dayStart->format('Y-m-d H:i:s'),
            'day_end' => $dayEnd->format('Y-m-d H:i:s'),
        ];
        if ($employeeFilter !== null) {
            $latestIdleWhere .= ' AND we.employee_id = :employee_id';
            $latestIdleParams['employee_id'] = $employeeFilter;
        }
        $latestIdleStmt = $db->prepare(
            'SELECT we.idle_seconds, we.occurred_at, d.hostname,
                    CONCAT(e.first_name, " ", e.last_name) AS employee_name
             FROM windows_events we
             JOIN windows_event_types wet ON wet.id = we.event_type_id
             JOIN devices d ON d.id = we.device_id
             LEFT JOIN employees e ON e.id = we.employee_id
             ' . $latestIdleWhere . '
             ORDER BY we.occurred_at DESC, we.id DESC
             LIMIT 1'
        );
        $latestIdle = null;
        if ($showEvidence) {
            $latestIdleStmt->execute($latestIdleParams);
            $latestIdle = $latestIdleStmt->fetch() ?: null;
        }

        $summaryStmt = $db->prepare(
            'SELECT
                SUM(status = "pending") AS pending_total,
                SUM(status = "pending" AND severity = "high") AS high_total
             FROM anomaly_alerts
             WHERE company_id = :company_id
               AND created_at >= :day_start AND created_at < :day_end'
        );
        $summaryStmt->execute([
            'company_id' => $user->companyId,
            'day_start' => $dayStart->format('Y-m-d H:i:s'),
            'day_end' => $dayEnd->format('Y-m-d H:i:s'),
        ]);
        $alertSummary = $summaryStmt->fetch() ?: ['pending_total' => 0, 'high_total' => 0];

        $evidenceTodayStmt = $db->prepare(
            'SELECT COUNT(*)
             FROM windows_events we
             JOIN windows_event_types wet ON wet.id = we.event_type_id
             JOIN devices d ON d.id = we.device_id
             WHERE d.company_id = :company_id
               AND we.occurred_at >= :day_start AND we.occurred_at < :day_end
               AND wet.code <> "activity" AND wet.code <> "idle"'
        );
        $evidenceTodayStmt->execute([
            'company_id' => $user->companyId,
            'day_start' => $dayStart->format('Y-m-d H:i:s'),
            'day_end' => $dayEnd->format('Y-m-d H:i:s'),
        ]);

        return $this->view('alerts/index', [
            'user' => $user,
            'alerts' => $alerts,
            'types' => $types,
            'currentStatus' => $status,
            'currentSeverity' => $severity,
            'currentType' => $type,
            'currentEventType' => $eventType,
            'selectedEmployeeId' => $employeeFilter,
            'total' => $total,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => max(1, ceil($total / $perPage)),
            'employees' => $employees->fetchAll(),
            'eventTypes' => $eventTypes,
            'events' => $events,
            'activityEvidence' => $activityEvidence,
            'flexibleEvidence' => $flexibleEvidence,
            'trackingEvidence' => $trackingEvidence,
            'latestIdle' => $latestIdle,
            'showEvidence' => $showEvidence,
            'pendingTotal' => (int) ($alertSummary['pending_total'] ?? 0),
            'highTotal' => (int) ($alertSummary['high_total'] ?? 0),
            'evidenceToday' => (int) $evidenceTodayStmt->fetchColumn(),
            'selectedDate' => $selectedDate,
            'selectedDateLabel' => $selectedDay->format('l, d F Y'),
            'previousDate' => $selectedDay->modify('-1 day')->format('Y-m-d'),
            'nextDate' => $selectedDay < $today ? $selectedDay->modify('+1 day')->format('Y-m-d') : null,
            'flash' => Session::pull('_flash'),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $user = Auth::user();

        $alertId = (int) $id;
        $stmt = Database::connection()->prepare(
            'SELECT aa.*, e.employee_number, CONCAT(e.first_name, " ", e.last_name) AS employee_name,
                    u.display_name AS reviewer_name
             FROM anomaly_alerts aa
             JOIN employees e ON e.id = aa.employee_id
             LEFT JOIN users u ON u.id = aa.reviewed_by_user_id
             WHERE aa.id = :id AND aa.company_id = :company_id'
        );
        $stmt->execute(['id' => $alertId, 'company_id' => $user->companyId]);
        $alert = $stmt->fetch();

        if (!$alert) {
            return $this->view('errors/404', [], 404);
        }

        // Get related event details if available
        $relatedData = null;
        if ($alert['related_event_id']) {
            $eventStmt = Database::connection()->prepare(
                'SELECT ae.*, et.name, et.code FROM attendance_events ae
                 JOIN attendance_event_types et ON et.id = ae.event_type_id
                 WHERE ae.id = :id'
            );
            $eventStmt->execute(['id' => $alert['related_event_id']]);
            $relatedData = $eventStmt->fetch();
        } elseif ($alert['related_window_event_id']) {
            $eventStmt = Database::connection()->prepare(
                'SELECT we.*, wet.name FROM windows_events we
                 JOIN windows_event_types wet ON wet.id = we.event_type_id
                 WHERE we.id = :id'
            );
            $eventStmt->execute(['id' => $alert['related_window_event_id']]);
            $relatedData = $eventStmt->fetch();
        }

        return $this->view('alerts/show', [
            'user' => $user,
            'alert' => $alert,
            'relatedData' => $relatedData,
        ]);
    }

    public function review(Request $request, string $id): Response
    {
        $user = Auth::user();

        $alertId = (int) $id;
        $action = $request->input('action');
        $notes = trim((string) $request->input('notes', ''));

        if (!in_array($action, ['dismiss', 'escalate'], true)) {
            return $this->json(['error' => 'Invalid action'], 400);
        }

        $stmt = Database::connection()->prepare(
            'UPDATE anomaly_alerts
             SET status = :status, reviewed_by_user_id = :user_id, review_notes = :notes, reviewed_at = NOW()
             WHERE id = :id AND company_id = :company_id'
        );
        $result = $stmt->execute([
            'status' => $action === 'dismiss' ? 'dismissed' : 'escalated',
            'user_id' => $user->id,
            'notes' => $notes ?: null,
            'id' => $alertId,
            'company_id' => $user->companyId,
        ]);

        if ($result) {
            Session::flash('success', 'Alert ' . ($action === 'dismiss' ? 'dismissed' : 'escalated') . ' successfully.');
        }

        return Response::redirect(app_url('/alerts/' . $alertId));
    }
}
