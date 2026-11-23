<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($report['employee']['employee_name']) ?> activity | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20261013-export-button')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<?php
$employee = $report['employee'];
$exportQuery = http_build_query(['start_date' => $report['start'], 'end_date' => $report['end']]);
?>
<main class="container employee-activity-page">
    <div class="page-heading employee-activity-heading">
        <div>
            <p class="eyebrow">Employee activity report</p>
            <h1><?= e($employee['employee_name']) ?></h1>
            <p class="muted"><?= e($employee['employee_number']) ?><?= $employee['department_name'] ? ' · ' . e($employee['department_name']) : '' ?> · <?= e($report['range_label']) ?></p>
        </div>
        <div class="heading-actions">
            <a class="button-quiet" href="<?= e(app_url('/reports/activity?employee_id=' . $employee['id'] . '&start_date=' . $report['start'] . '&end_date=' . $report['end'])) ?>">← Productivity</a>
            <a class="button" href="<?= e(app_url('/reports/employees/' . $employee['id'] . '/activity.pdf?' . $exportQuery)) ?>">Export PDF</a>
        </div>
    </div>

    <section class="panel employee-report-filter">
        <form method="get" class="date-range-form">
            <div><label for="start_date">From</label><input id="start_date" type="date" name="start_date" value="<?= e($report['start']) ?>"></div>
            <div><label for="end_date">To</label><input id="end_date" type="date" name="end_date" value="<?= e($report['end']) ?>"></div>
            <button type="submit">Update report</button>
        </form>
    </section>

    <section class="employee-report-metrics" aria-label="Employee report summary">
        <article><span>Tracked activity</span><strong><?= e(employee_activity_duration((int) $report['totals']['tracked_seconds'])) ?></strong><small>Foreground apps and sites</small></article>
        <article><span>Estimated idle</span><strong><?= e(employee_activity_duration((int) $report['totals']['idle_seconds'])) ?></strong><small><?= e((int) $report['totals']['idle_episodes']) ?> detected episodes</small></article>
        <article><span>Significant events</span><strong><?= e((int) $report['totals']['significant_events']) ?></strong><small>Attendance, Windows and alerts</small></article>
        <article><span>Applications</span><strong><?= e((int) $report['totals']['app_count']) ?></strong><small>Distinct applications</small></article>
        <article><span>Websites</span><strong><?= e((int) $report['totals']['website_count']) ?></strong><small>Distinct domains</small></article>
        <article class="<?= (int) $report['totals']['late_days'] > 0 ? 'metric-warning' : '' ?>"><span>Late days</span><strong><?= e((int) $report['totals']['late_days']) ?></strong><small>Outside shift grace period</small></article>
    </section>

    <section class="panel employee-daily-panel">
        <div class="panel-heading"><div><p class="eyebrow">Day by day</p><h2>Daily activity overview</h2><p>Open a day to see its applications and websites.</p></div></div>
        <div class="employee-day-list">
            <?php foreach ($report['days'] as $day): ?>
                <details class="employee-day" <?= $day['date'] === $report['end'] ? 'open' : '' ?>>
                    <summary>
                        <div class="day-identity"><span><?= e($day['day_name']) ?></span><strong><?= e(local_datetime($day['date'] . ' 00:00:00', 'd M Y')) ?></strong></div>
                        <dl>
                            <div><dt>In / out</dt><dd><?= e($day['first_in'] ? local_datetime((string) $day['first_in'], 'H:i') : '—') ?> – <?= e($day['last_out'] ? local_datetime((string) $day['last_out'], 'H:i') : '—') ?></dd></div>
                            <div><dt>Worked</dt><dd><?= e(employee_activity_duration((int) $day['work_seconds'])) ?></dd></div>
                            <div><dt>Tracked</dt><dd><?= e(employee_activity_duration((int) $day['tracked_seconds'])) ?></dd></div>
                            <div><dt>Idle</dt><dd><?= e(employee_activity_duration((int) $day['idle_seconds'])) ?></dd></div>
                            <div><dt>Apps / sites</dt><dd><?= e(count($day['apps'])) ?> / <?= e(count($day['websites'])) ?></dd></div>
                        </dl>
                        <?php if ((int) $day['late_minutes'] > 0): ?><span class="late-pill"><?= e((int) $day['late_minutes']) ?>m late</span><?php endif; ?>
                    </summary>
                    <div class="employee-day-details">
                        <div class="day-breakdown">
                            <div><span>Productive</span><strong><?= e(employee_activity_duration((int) $day['productive_seconds'])) ?></strong></div>
                            <div><span>Neutral</span><strong><?= e(employee_activity_duration((int) $day['neutral_seconds'])) ?></strong></div>
                            <div><span>Distracting</span><strong><?= e(employee_activity_duration((int) $day['distracting_seconds'])) ?></strong></div>
                            <div><span>Unclassified</span><strong><?= e(employee_activity_duration((int) $day['unclassified_seconds'])) ?></strong></div>
                            <div><span>Longest idle</span><strong><?= e(employee_activity_duration((int) $day['longest_idle_seconds'])) ?></strong></div>
                            <div><span>Idle evidence</span><strong><?= e((int) $day['idle_episodes']) ?> episodes</strong><small><?= e((int) $day['idle_samples']) ?> samples</small></div>
                        </div>
                        <div class="daily-source-grid">
                            <section><h3>Applications</h3><div class="daily-source-list">
                                <?php foreach (array_slice($day['apps'], 0, 15) as $source): ?><article><div><strong><?= e($source['name']) ?></strong><small><?= e(ucfirst($source['classification'])) ?></small></div><b><?= e(employee_activity_duration((int) $source['duration_seconds'])) ?></b></article><?php endforeach; ?>
                                <?php if ($day['apps'] === []): ?><p class="empty">No application activity.</p><?php endif; ?>
                            </div></section>
                            <section><h3>Websites</h3><div class="daily-source-list">
                                <?php foreach (array_slice($day['websites'], 0, 15) as $source): ?><article><div><strong><?= e($source['name']) ?></strong><small><?= e(ucfirst($source['classification'])) ?></small></div><b><?= e(employee_activity_duration((int) $source['duration_seconds'])) ?></b></article><?php endforeach; ?>
                                <?php if ($day['websites'] === []): ?><p class="empty">No website activity.</p><?php endif; ?>
                            </div></section>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel review-table-panel employee-events-panel">
        <div class="review-section-heading"><div><span class="section-icon section-icon-alert" aria-hidden="true">!</span><div><p class="eyebrow">Audit trail</p><h2>Significant events</h2><p>Routine keyboard, mouse and idle samples are excluded.</p></div></div><span class="result-count"><?= e(count($report['events'])) ?> events</span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Occurred</th><th>Group</th><th>Event</th><th>Source / device</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($report['events'] as $event): ?>
                <tr>
                    <td><strong><?= e(local_datetime((string) $event['occurred_at'], 'd M H:i')) ?></strong><small>SAST</small></td>
                    <td><span class="event-group event-group-<?= e(strtolower((string) $event['event_group'])) ?>"><?= e($event['event_group']) ?></span></td>
                    <td><strong><?= e(ucwords((string) $event['event_name'])) ?></strong><?php if ($event['severity'] ?? ''): ?><small><?= e(ucfirst((string) $event['severity'])) ?> priority</small><?php endif; ?></td>
                    <td><?= e($event['source']) ?><small><?= e($event['device_name'] ?? '') ?></small></td>
                    <td><?= e($event['detail'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($report['events'] === []): ?><tr><td colspan="5" class="empty">No significant events in this period.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

    <p class="report-method-note"><strong>Idle-time note:</strong> idle events are cumulative samples sent by the Windows agent. Estimated daily idle combines consecutive samples into episodes instead of adding every sample, which would overstate the result.</p>
</main>
</body>
</html>

<?php
function employee_activity_duration(int $seconds): string
{
    $hours = intdiv(max(0, $seconds), 3600);
    $minutes = intdiv(max(0, $seconds) % 3600, 60);
    return $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
}
?>
