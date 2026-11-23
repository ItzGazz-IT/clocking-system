<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weekly report | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container">
    <div class="page-heading">
        <div><p class="eyebrow">Reports</p><h1>Weekly attendance</h1></div>
        <p class="muted"><?= e($report['start']) ?> to <?= e($report['end']) ?></p>
    </div>

    <section class="panel report-toolbar">
        <form method="get" action="<?= e(app_url('/reports/weekly')) ?>" class="filter-actions">
            <div>
                <label for="week">Week</label>
                <input id="week" name="week" type="date" value="<?= e($week) ?>">
            </div>
            <div>
                <label for="employee_id">Employee</label>
                <select id="employee_id" name="employee_id">
                    <option value="">All employees</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= e($employee['id']) ?>" <?= $selectedEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>>
                            <?= e($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_number'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">View week</button>
            <?php $exportQuery = http_build_query(array_filter(['week' => $week, 'employee_id' => $selectedEmployeeId ?: null], static fn ($value): bool => $value !== null && $value !== '')); ?>
            <a class="button-quiet" href="<?= e(app_url('/reports/weekly.pdf?' . $exportQuery)) ?>">Export branded PDF</a>
        </form>
    </section>

    <?php $reportedDays = array_sum(array_map(static fn (array $employee): int => count($employee['days']), $report['employee_groups'])); ?>
    <section class="metrics report-summary">
        <article><span>Employees</span><strong><?= e(count($report['employee_groups'])) ?></strong></article>
        <article><span>Days recorded</span><strong><?= e($reportedDays) ?></strong></article>
        <article><span>Sessions</span><strong><?= e($report['totals']['sessions']) ?></strong></article>
        <article><span>Week</span><strong><?= e(date('d M', strtotime($report['start']))) ?></strong></article>
    </section>

    <p class="muted">Work includes overtime. Overtime shows recorded time above the daily target, before approval. For automatic attendance, inactive time includes gaps without agent reports.</p>
    <section class="weekly-report">
        <?php foreach ($report['employee_groups'] as $employee): ?>
            <?php $inactiveLabel = ($employee['attendance_mode'] ?? 'fixed') === 'flexible' ? 'Inactive' : 'Lunch'; ?>
            <article class="weekly-employee-card">
                <header class="weekly-employee-header">
                    <div>
                        <span class="eyebrow"><?= e($employee['employee_number']) ?><?= $employee['department_name'] ? ' · ' . e($employee['department_name']) : '' ?></span>
                        <h2><?= e($employee['employee_name']) ?></h2>
                    </div>
                    <div class="weekly-total-strip">
                        <span><b>Work</b><?= e(format_minutes($employee['totals']['work'])) ?></span>
                        <span><b><?= e($inactiveLabel) ?></b><?= e(format_minutes($employee['totals']['break'])) ?></span>
                        <span><b>Overtime</b><?= e(format_minutes($employee['totals']['overtime'])) ?></span>
                    </div>
                </header>

                <div class="weekly-table-wrap">
                    <table class="weekly-table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Date</th>
                                <th>Clock in</th>
                                <th>Clock out</th>
                                <th><?= e($inactiveLabel) ?></th>
                                <th>Work</th>
                                <th>Overtime</th>
                                <th>Sessions</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($employee['days'] as $day): ?>
                            <tr class="<?= $day['status'] === 'open' ? 'open' : '' ?>">
                                <td><strong><?= e($day['day_name']) ?></strong><small><?= e(count($day['sessions'])) ?> <?= count($day['sessions']) === 1 ? 'session' : 'sessions' ?></small></td>
                                <td><?= e($day['work_date']) ?></td>
                                <td><?= e($day['first_in'] ? local_datetime($day['first_in'], 'H:i') : '—') ?></td>
                                <td><?= e($day['last_out'] ? local_datetime($day['last_out'], 'H:i') : '—') ?></td>
                                <td><?= e(format_minutes($day['break_minutes'])) ?></td>
                                <td><?= e(format_minutes($day['work_minutes'])) ?></td>
                                <td><?= e(format_minutes($day['overtime_minutes'])) ?></td>
                                <td class="session-times"><?= e($day['session_summary']) ?></td>
                                <td><span class="badge"><?= e(ucfirst($day['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4">Employee total</th>
                                <th><?= e(format_minutes($employee['totals']['break'])) ?></th>
                                <th><?= e(format_minutes($employee['totals']['work'])) ?></th>
                                <th><?= e(format_minutes($employee['totals']['overtime'])) ?></th>
                                <th><?= e($employee['totals']['sessions']) ?> sessions</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($report['employee_groups'] === []): ?>
            <section class="panel empty-state">
                <h2>No attendance recorded</h2>
                <p>No clock-ins were found for this employee and week.</p>
            </section>
        <?php endif; ?>
    </section>
</main>
</body></html>
