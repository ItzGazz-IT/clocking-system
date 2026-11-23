<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance history | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<?php
$totalWorked = array_sum(array_map(static fn (array $record): int => (int) ($record['calculated_work_minutes'] ?? 0), $records));
$totalBreaks = array_sum(array_map(static fn (array $record): int => (int) ($record['calculated_break_minutes'] ?? 0), $records));
$totalOvertime = array_sum(array_map(static fn (array $record): int => (int) ($record['calculated_overtime_minutes'] ?? 0), $records));
$openSessions = count(array_filter($records, static fn (array $record): bool => ($record['status'] ?? '') !== 'closed'));
$isFlexible = ($employee['attendance_mode'] ?? 'fixed') === 'flexible';
$inactiveLabel = $isFlexible ? 'Inactive' : 'Lunch';
$eventsByAttendance = [];
$correctionsByOriginal = [];
foreach ($events as $event) {
    $eventsByAttendance[(int) $event['attendance_id']][] = $event;
    if ($event['code'] === 'correction' && $event['correction_of_event_id'] !== null
        && !isset($correctionsByOriginal[(int) $event['correction_of_event_id']])) {
        $correctionsByOriginal[(int) $event['correction_of_event_id']] = json_decode((string) ($event['metadata'] ?? '{}'), true) ?: [];
    }
}
?>
<main class="container">
    <div class="page-heading attendance-heading">
        <div>
            <p class="eyebrow">Attendance record</p>
            <h1><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></h1>
            <p class="muted"><?= e($employee['employee_number']) ?></p>
        </div>
        <?php if ($user->can('employees.view')): ?><a class="back-link" href="<?= e(app_url('/employees')) ?>">← Back to employees</a><?php endif; ?>
    </div>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <?php if ($user->can('attendance.view.all')): ?>
        <section class="panel employee-attendance-switcher">
            <form method="get" action="<?= e(app_url('/attendance/history')) ?>" class="filter-actions">
                <label>Employee
                    <select name="employee_id">
                        <?php foreach ($employees as $item): ?>
                            <option value="<?= e($item['id']) ?>" <?= (int) $selectedEmployeeId === (int) $item['id'] ? 'selected' : '' ?>>
                                <?= e($item['first_name'] . ' ' . $item['last_name'] . ' (' . $item['employee_number'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">View employee</button>
                <a class="button-quiet" href="<?= e(app_url('/reports/weekly?employee_id=' . $selectedEmployeeId)) ?>">Weekly report</a>
                <a class="button-quiet" href="<?= e(app_url('/reports/weekly.pdf?employee_id=' . $selectedEmployeeId)) ?>">Export PDF</a>
            </form>
        </section>
    <?php endif; ?>

    <section class="attendance-overview">
        <article><span>Recorded days</span><strong><?= e((string) count($records)) ?></strong><small><?= e((string) $openSessions) ?> open sessions</small></article>
        <article><span>Worked time</span><strong><?= e(format_minutes($totalWorked)) ?></strong><small>Total shown below</small></article>
        <article><span><?= e($inactiveLabel) ?> time</span><strong><?= e(format_minutes($totalBreaks)) ?></strong><small>Total <?= e(strtolower($inactiveLabel)) ?> time</small></article>
        <article><span>Overtime</span><strong><?= e(format_minutes($totalOvertime)) ?></strong><small>Calculated total</small></article>
    </section>

    <p class="muted">Worked time includes overtime. Overtime shows recorded time above the daily target, before approval. For automatic attendance, inactive time includes gaps without agent reports.</p>

    <?php if ($user->can('attendance.correct') && $records !== [] && !$isFlexible): ?>
    <section class="panel manual-event-panel">
        <div><p class="eyebrow">Missing punch</p><h2>Append an event</h2><p class="muted">Use this only when a genuine clock, break, or lunch event was missed. The action is permanently audited.</p></div>
        <form method="post" action="<?= e(app_url('/attendance/' . $records[0]['id'] . '/events/add')) ?>">
            <?= csrf_field() ?>
            <div class="form-row three"><div><label>Attendance day</label><select name="attendance_id" required><?php foreach ($records as $record): ?><option value="<?= e($record['id']) ?>"><?= e(date('d M Y', strtotime($record['work_date']))) ?> · <?= e(ucfirst($record['session_type'] ?? 'regular')) ?> · Session <?= e($record['session_number'] ?? 1) ?></option><?php endforeach; ?></select></div>
            <div><label>Event</label><select name="event" required><option value="clock_in">Clock in</option><option value="clock_out">Clock out</option><option value="lunch_start">Start lunch</option><option value="lunch_end">End lunch</option></select></div>
            <div><label>Date and time</label><input name="occurred_at" type="datetime-local" value="<?= e(date('Y-m-d\TH:i')) ?>" required></div>
            <div><label>Reason</label><input name="reason" minlength="10" required></div></div>
            <button type="submit">Append missing event</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="attendance-days">
        <div class="panel-heading">
            <div><p class="eyebrow">Daily summary</p><h2>Recent attendance</h2></div>
        </div>
        <div class="attendance-card-list">
            <?php foreach ($records as $record): ?>
                <article class="attendance-day-card <?= $record['status'] === 'closed' ? '' : 'open' ?>">
                    <div class="attendance-date">
                        <strong><?= e(date('d', strtotime($record['work_date']))) ?></strong>
                        <span><?= e(date('M Y', strtotime($record['work_date']))) ?></span>
                        <small>Session <?= e($record['session_number'] ?? 1) ?></small>
                    </div>
                    <div class="attendance-times">
                        <div><span>In</span><strong><?= e($record['opened_at'] ? local_datetime($record['opened_at'], 'H:i') : '-') ?></strong></div>
                        <div><span>Out</span><strong><?= e($record['closed_at'] ? local_datetime($record['closed_at'], 'H:i') : '-') ?></strong></div>
                    </div>
                    <div class="attendance-totals">
                        <span><b>Worked</b><?= e(format_minutes($record['calculated_work_minutes'])) ?></span>
                        <span><b><?= e($inactiveLabel) ?></b><?= e(format_minutes($record['calculated_break_minutes'])) ?></span>
                        <span><b>Overtime</b><?= e(format_minutes($record['calculated_overtime_minutes'])) ?></span>
                    </div>
                    <span class="badge <?= $record['status'] === 'closed' ? '' : 'badge-warning' ?>"><?= e(ucfirst($record['status'])) ?></span>
                    <?php $dayEvents = array_values(array_filter($eventsByAttendance[(int) $record['id']] ?? [], static fn (array $event): bool => $event['code'] !== 'correction')); ?>
                    <?php $activityGroups = $activityEvidence[(int) $record['id']] ?? []; $activityCount = array_sum(array_column($activityGroups, 'samples')); ?>
                    <details class="day-event-manager">
                        <summary><span><?= $isFlexible ? 'Recorded activity' : 'Manage daily events' ?></span><small><?= e((string) (count($dayEvents) + $activityCount)) ?> recorded</small></summary>
                        <div class="day-event-list">
                            <?php foreach ($activityGroups as $activityGroup): ?>
                                <article class="day-event-row"><div class="day-event-copy">
                                    <strong><?= e($activityGroup['name']) ?></strong>
                                    <span><?= e(local_datetime($activityGroup['first_at'], 'H:i')) ?> to <?= e(local_datetime($activityGroup['last_at'], 'H:i')) ?></span>
                                    <small><?= e((string) $activityGroup['samples']) ?> agent records · first and last event times</small>
                                </div></article>
                            <?php endforeach; ?>
                            <?php foreach ($dayEvents as $event): ?>
                                <?php $correction = $correctionsByOriginal[(int) $event['id']] ?? null; $isVoided = ($correction['corrected_event'] ?? '') === 'void'; ?>
                                <article class="day-event-row <?= $isVoided ? 'voided' : '' ?>">
                                    <?php $eventLabel = match ($event['code']) { 'break_start' => 'Lunch started', 'break_end' => 'Lunch ended', default => $event['name'] }; ?>
                                    <div class="day-event-copy"><strong><?= e($eventLabel) ?></strong><span><?= e(local_datetime($event['occurred_at'], 'd M Y · H:i')) ?></span><small><?= e(ucfirst(str_replace('_', ' ', $event['source']))) ?><?= $event['correction_reason'] ? ' · ' . e($event['correction_reason']) : '' ?></small></div>
                                    <?php if ($correction): ?><span class="event-state <?= $isVoided ? 'voided' : 'corrected' ?>"><?= $isVoided ? 'Voided' : 'Corrected to ' . e(ucwords(str_replace('_', ' ', $correction['corrected_event'] ?? ''))) ?></span><?php endif; ?>
                                    <?php if ($user->can('attendance.correct') && !$isVoided): ?>
                                        <div class="day-event-actions">
                                            <details class="correction-form"><summary>Change</summary><form method="post" action="<?= e(app_url('/attendance/events/' . $event['id'] . '/correct')) ?>">
                                                <?= csrf_field() ?><label>Event</label><select name="corrected_event" required><?php foreach(['clock_in'=>'Clock in','clock_out'=>'Clock out','break_start'=>'Lunch started (legacy)','break_end'=>'Lunch ended (legacy)','lunch_start'=>'Start lunch','lunch_end'=>'End lunch'] as $code=>$label):?><option value="<?= e($code) ?>" <?= $event['code']===$code?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select>
                                                <label>Date and time</label><input name="corrected_at" type="datetime-local" value="<?= e(local_datetime($event['occurred_at'], 'Y-m-d\TH:i')) ?>" required><label>Reason</label><textarea name="reason" rows="2" minlength="10" required></textarea><button type="submit">Save change</button>
                                            </form></details>
                                            <details class="correction-form void-form"><summary>Void</summary><form method="post" action="<?= e(app_url('/attendance/events/' . $event['id'] . '/correct')) ?>">
                                                <?= csrf_field() ?><input type="hidden" name="corrected_event" value="void"><input type="hidden" name="corrected_at" value="<?= e(local_datetime($event['occurred_at'], 'Y-m-d\TH:i')) ?>"><p class="muted">Exclude this event from this day’s totals.</p><label>Reason</label><textarea name="reason" rows="2" minlength="10" required></textarea><button type="submit" class="danger-button">Void event</button>
                                            </form></details>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                            <?php if ($dayEvents === [] && $activityGroups === []): ?><p class="empty"><?= $isFlexible ? 'No agent activity is available for this day. Working time cannot be confirmed from clock-in alone.' : 'No events recorded for this session.' ?></p><?php endif; ?>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
            <?php if ($records === []): ?><p class="empty">No attendance has been recorded yet.</p><?php endif; ?>
        </div>
    </section>

    <?php if (false): // Replaced by per-day event management above. ?>
    <section class="panel event-panel">
        <div class="panel-heading"><div><p class="eyebrow">Timeline</p><h2>Event history</h2></div><span class="timeline-count"><?= count($events) ?> events</span></div>
        <div class="event-timeline">
        <?php foreach ($events as $event): $metadata = json_decode((string) ($event['metadata'] ?? '{}'), true) ?: []; ?>
            <article class="timeline-event <?= $event['code'] === 'correction' ? 'correction' : '' ?>">
                <span class="timeline-dot"></span>
                <div class="timeline-main">
                    <div><strong><?= e($event['name']) ?></strong><span><?= e(local_datetime($event['occurred_at'])) ?></span></div>
                    <p><?= e(ucfirst(str_replace('_', ' ', $event['source']))) ?><?= $event['correction_reason'] ? ' · ' . e($event['correction_reason']) : '' ?></p>
                    <?php if ($event['code'] === 'correction'): ?><div class="correction-note"><?= ($metadata['corrected_event'] ?? '') === 'void' ? 'Voided' : 'Corrected to ' . e(ucwords(str_replace('_', ' ', $metadata['corrected_event'] ?? ''))) . ' at ' . e(local_datetime($metadata['corrected_occurred_at'] ?? null)) ?>. Original event #<?= e($event['correction_of_event_id']) ?> remains preserved.</div><?php endif; ?>
                </div>
                <?php if ($user->can('attendance.correct') && $event['code'] !== 'correction'): ?>
                    <details class="correction-form"><summary>Correct</summary><form method="post" action="<?= e(app_url('/attendance/events/' . $event['id'] . '/correct')) ?>">
                        <?= csrf_field() ?>
                        <label>Correct event</label><select name="corrected_event" required><?php foreach(['clock_in'=>'Clock in','clock_out'=>'Clock out','break_start'=>'Start break','break_end'=>'End break','lunch_start'=>'Start lunch','lunch_end'=>'End lunch'] as $code=>$label):?><option value="<?= e($code) ?>" <?= $event['code']===$code?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select>
                        <label>Correct date and time</label><input name="corrected_at" type="datetime-local" value="<?= e(local_datetime($event['occurred_at'], 'Y-m-d\TH:i')) ?>" required>
                        <label>Reason</label><textarea name="reason" rows="2" minlength="10" required></textarea>
                        <button type="submit">Append correction</button>
                    </form></details>
                    <details class="correction-form void-form"><summary>Void event</summary><form method="post" action="<?= e(app_url('/attendance/events/' . $event['id'] . '/correct')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="corrected_event" value="void"><input type="hidden" name="corrected_at" value="<?= e(local_datetime($event['occurred_at'], 'Y-m-d\TH:i')) ?>">
                        <p class="muted">Exclude this event from attendance totals. The original remains permanently audited.</p>
                        <label>Reason</label><textarea name="reason" rows="2" minlength="10" required></textarea>
                        <button type="submit" class="danger-button">Void event</button>
                    </form></details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if ($events === []): ?><p class="empty">No event history yet.</p><?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
</main>
</body></html>
