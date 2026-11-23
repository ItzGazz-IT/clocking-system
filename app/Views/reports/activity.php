<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Productivity insights | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260727-branded-pdf-export')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php include __DIR__ . '/../partials/nav.php'; ?>
<?php
$trackedSeconds = (int) ($summary['tracked_seconds'] ?? 0);
$productiveSeconds = (int) ($summary['focus_seconds'] ?? 0);
$neutralSeconds = (int) ($summary['neutral_seconds'] ?? 0);
$distractingSeconds = (int) ($summary['risk_seconds'] ?? 0);
$unclassifiedSeconds = (int) ($summary['unclassified_seconds'] ?? 0);
$classifiedSeconds = $productiveSeconds + $neutralSeconds + $distractingSeconds;
$scoredSeconds = $productiveSeconds + $distractingSeconds;
$focusScore = $scoredSeconds > 0 ? min(100, (int) round(($productiveSeconds / $scoredSeconds) * 100)) : 0;
$confidence = $trackedSeconds > 0 ? min(100, (int) round(($classifiedSeconds / $trackedSeconds) * 100)) : 0;
$filterValues = [
    'start_date' => $startDate, 'end_date' => $endDate,
    'employee_id' => $selectedEmployeeId, 'department_id' => $selectedDepartmentId,
    'classification' => $selectedClassification, 'source' => $sourceFilter,
    'minimum_minutes' => $minimumMinutes ?: null,
];
$returnQuery = http_build_query(array_filter($filterValues, static fn ($value): bool => $value !== null && $value !== ''));
?>
<main class="container productivity-page insights-page">
    <div class="page-heading productivity-heading">
        <div>
            <p class="eyebrow">Productivity intelligence</p>
            <h1><?= $selectedEmployeeId ? 'Employee activity' : 'Team activity' ?></h1>
            <p class="muted"><?= e($rangeLabel) ?> · Scores only use classified productive and distracting time.</p>
        </div>
        <div class="heading-actions">
            <?php if ($selectedEmployeeId): ?>
                <a class="button-quiet" href="<?= e(activity_report_url($filterValues, ['employee_id' => null])) ?>">← Back to team view</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <details class="panel pdf-export-panel">
        <summary><span><strong>Export branded PDF</strong><small>Choose a date range and one or more employees</small></span><b>PDF</b></summary>
        <form method="get" action="<?= e(app_url('/reports/activity.pdf')) ?>" class="pdf-export-form">
            <div><label for="export_start_date">From</label><input id="export_start_date" type="date" name="start_date" value="<?= e($startDate) ?>" required></div>
            <div><label for="export_end_date">To</label><input id="export_end_date" type="date" name="end_date" value="<?= e($endDate) ?>" required></div>
            <div class="pdf-export-employees">
                <label for="export_employee_ids">Employees</label>
                <select id="export_employee_ids" name="employee_ids[]" multiple size="6">
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= e($employee['id']) ?>" <?= $selectedEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>><?= e($employee['first_name'] . ' ' . $employee['last_name'] . ' (' . $employee['employee_number'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Leave all unselected to include everyone. Hold Ctrl to select multiple employees.</small>
            </div>
            <?php if ($selectedDepartmentId): ?><input type="hidden" name="department_id" value="<?= e($selectedDepartmentId) ?>"><?php endif; ?>
            <button type="submit">Download colour PDF</button>
        </form>
    </details>

    <section class="panel productivity-filter-panel">
        <div class="panel-heading">
            <div><h2>Explore activity</h2><p>Use up to 31 days. Employee and department filters apply across every insight.</p></div>
            <a class="button-quiet" href="<?= e(app_url('/reports/activity')) ?>">Reset</a>
        </div>
        <form method="get" action="<?= e(app_url('/reports/activity')) ?>" class="productivity-filter-grid">
            <div><label for="start_date">From</label><input id="start_date" type="date" name="start_date" value="<?= e($startDate) ?>"></div>
            <div><label for="end_date">To</label><input id="end_date" type="date" name="end_date" value="<?= e($endDate) ?>"></div>
            <div>
                <label for="employee_id">Employee</label>
                <select id="employee_id" name="employee_id">
                    <option value="">All employees</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= e($employee['id']) ?>" <?= $selectedEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e($department['id']) ?>" <?= $selectedDepartmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="classification">Classification</label>
                <select id="classification" name="classification">
                    <option value="">All classifications</option>
                    <?php foreach (['productive' => 'Productive', 'neutral' => 'Neutral', 'distracting' => 'Distracting', 'unclassified' => 'Unclassified'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedClassification === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="source">App or website</label><input id="source" name="source" value="<?= e($sourceFilter) ?>" placeholder="e.g. Excel or example.com"></div>
            <div><label for="minimum_minutes">Minimum tracked</label><select id="minimum_minutes" name="minimum_minutes"><option value="0">Any duration</option><?php foreach ([30, 60, 120, 240] as $minutes): ?><option value="<?= e($minutes) ?>" <?= $minimumMinutes === $minutes ? 'selected' : '' ?>><?= e($minutes >= 60 ? ($minutes / 60) . ' hours' : $minutes . ' minutes') ?></option><?php endforeach; ?></select></div>
            <button type="submit">Apply filters</button>
        </form>
    </section>

    <section class="productivity-hero insight-metrics" aria-label="Productivity summary">
        <article class="focus-score trusted-score">
            <span>Trusted focus score</span>
            <strong><?= e($focusScore) ?>%</strong>
            <small>Productive ÷ scored time</small>
        </article>
        <article class="confidence-card">
            <span>Score confidence</span>
            <strong><?= e($confidence) ?>%</strong>
            <small><?= e(activity_duration($unclassifiedSeconds)) ?> still unclassified</small>
        </article>
        <article>
            <span>Tracking coverage</span>
            <strong><?= e((int) $summary['coverage_percent']) ?>%</strong>
            <small><?= e(activity_duration($trackedSeconds)) ?> of <?= e(activity_duration((int) $summary['clocked_seconds'])) ?> clocked</small>
        </article>
        <article>
            <span>Productive</span>
            <strong><?= e(activity_duration($productiveSeconds)) ?></strong>
            <small><?= e(activity_percent($productiveSeconds, $trackedSeconds)) ?> of tracked time</small>
        </article>
        <article class="risk-metric">
            <span>Distracting</span>
            <strong><?= e(activity_duration($distractingSeconds)) ?></strong>
            <small><?= e(activity_percent($distractingSeconds, $trackedSeconds)) ?> of tracked time</small>
        </article>
        <article>
            <span>Active employees</span>
            <strong><?= e((int) ($summary['employees_seen'] ?? 0)) ?></strong>
            <small><?= e((int) ($summary['apps_used'] ?? 0) + (int) ($summary['websites_used'] ?? 0)) ?> apps and sites</small>
        </article>
    </section>

    <section class="insight-section" aria-labelledby="insights-title">
        <div class="section-title"><div><p class="eyebrow">What deserves attention</p><h2 id="insights-title">Automated insights</h2></div></div>
        <div class="insight-card-grid">
            <?php foreach ($insights as $insight): ?>
                <article class="insight-card insight-<?= e($insight['tone']) ?>">
                    <span class="insight-signal" aria-hidden="true"></span>
                    <div><strong><?= e($insight['title']) ?></strong><p><?= e($insight['detail']) ?></p></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="productivity-insight-grid">
        <section class="panel coverage-panel">
            <div class="panel-heading"><div><h2>Tracking coverage</h2><p>Foreground activity compared with calculated clocked time.</p></div><strong><?= e((int) $summary['coverage_percent']) ?>%</strong></div>
            <div class="coverage-track" role="img" aria-label="<?= e((int) $summary['coverage_percent']) ?> percent tracking coverage"><span style="width: <?= e((int) $summary['coverage_percent']) ?>%"></span></div>
            <div class="coverage-stats">
                <div><span>Clocked</span><strong><?= e(activity_duration((int) $summary['clocked_seconds'])) ?></strong></div>
                <div><span>Tracked</span><strong><?= e(activity_duration($trackedSeconds)) ?></strong></div>
                <div><span>Untracked</span><strong><?= e(activity_duration((int) $summary['untracked_seconds'])) ?></strong></div>
            </div>
            <p class="coverage-note">Coverage is supporting evidence, not proof of output. Meetings, calls, and offline work can legitimately appear untracked.</p>
        </section>

        <section class="panel classification-split-panel">
            <div class="panel-heading"><div><h2>Classification split</h2><p>Only productive and distracting time determine the focus score.</p></div></div>
            <div class="classification-bar" aria-label="Activity classification split">
                <?php foreach ([['productive', $productiveSeconds], ['neutral', $neutralSeconds], ['distracting', $distractingSeconds], ['unclassified', $unclassifiedSeconds]] as [$category, $seconds]): ?>
                    <span class="<?= e($category) ?>" style="width: <?= e(activity_raw_percent((int) $seconds, $trackedSeconds)) ?>%" title="<?= e(ucfirst($category) . ': ' . activity_duration((int) $seconds)) ?>"></span>
                <?php endforeach; ?>
            </div>
            <div class="classification-legend">
                <span class="productive"><i></i>Productive <b><?= e(activity_duration($productiveSeconds)) ?></b></span>
                <span class="neutral"><i></i>Neutral <b><?= e(activity_duration($neutralSeconds)) ?></b></span>
                <span class="distracting"><i></i>Distracting <b><?= e(activity_duration($distractingSeconds)) ?></b></span>
                <span class="unclassified"><i></i>Unclassified <b><?= e(activity_duration($unclassifiedSeconds)) ?></b></span>
            </div>
        </section>
    </div>

    <section class="panel review-table-panel productivity-people-panel">
        <div class="review-section-heading"><div><span class="section-icon section-icon-evidence" aria-hidden="true">↗</span><div><p class="eyebrow">Drill down</p><h2>Employee activity</h2></div></div><span class="result-count"><?= e(count($byEmployee)) ?> employees</span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Employee</th><th>Tracked</th><th>Productive</th><th>Neutral</th><th>Distracting</th><th>Confidence</th><th>Score</th></tr></thead>
            <tbody>
            <?php foreach ($byEmployee as $row): ?>
                <tr>
                    <td><a class="employee-drill-link" href="<?= e(app_url('/reports/employees/' . $row['employee_id'] . '/activity?start_date=' . $startDate . '&end_date=' . $endDate)) ?>"><strong><?= e($row['employee_name'] ?? 'Unassigned') ?></strong><small><?= e($row['department_name'] ?? $row['employee_number'] ?? '—') ?> · View daily report →</small></a></td>
                    <td><strong><?= e(activity_duration((int) $row['duration_seconds'])) ?></strong></td>
                    <td><?= e(activity_duration((int) $row['focus_seconds'])) ?></td>
                    <td><?= e(activity_duration((int) $row['neutral_seconds'])) ?></td>
                    <td><span class="<?= (int) $row['risk_seconds'] > 0 ? 'text-risk' : '' ?>"><?= e(activity_duration((int) $row['risk_seconds'])) ?></span></td>
                    <td><span class="confidence-pill <?= (int) $row['classification_confidence'] < 70 ? 'low' : '' ?>"><?= e((int) $row['classification_confidence']) ?>%</span></td>
                    <td><strong><?= e((int) $row['productivity_score']) ?>%</strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($byEmployee === []): ?><tr><td colspan="7" class="empty">No employee activity matches these filters.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>

    <section class="panel review-table-panel productivity-idle-panel">
        <div class="review-section-heading"><div><span class="section-icon section-icon-evidence" aria-hidden="true">◷</span><div><p class="eyebrow">Windows activity</p><h2>Daily idle time</h2><p>Estimated idle totals by employee and day for the selected date range.</p></div></div><span class="result-count"><?= e(count($dailyIdle)) ?> employee-days</span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Employee</th><th>Total idle</th><th>Longest idle</th><th>Episodes</th><th>Samples</th></tr></thead>
            <tbody>
            <?php foreach ($dailyIdle as $row): ?>
                <tr>
                    <td><strong><?= e(local_datetime((string) $row['date'] . ' 00:00:00', 'd M Y')) ?></strong></td>
                    <td><a class="employee-drill-link" href="<?= e(app_url('/reports/employees/' . $row['id'] . '/activity?start_date=' . $row['date'] . '&end_date=' . $row['date'])) ?>"><strong><?= e($row['employee_name']) ?></strong><small><?= e($row['department_name'] ?? $row['employee_number'] ?? '—') ?> · View day →</small></a></td>
                    <td><strong class="<?= (int) $row['idle_seconds'] >= 28800 ? 'text-risk' : '' ?>"><?= e(activity_duration((int) $row['idle_seconds'])) ?></strong></td>
                    <td><?= e(activity_duration((int) $row['longest_idle_seconds'])) ?></td>
                    <td><?= e((int) $row['idle_episodes']) ?></td>
                    <td><?= e((int) $row['idle_samples']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($dailyIdle === []): ?><tr><td colspan="6" class="empty">No employees match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <p class="coverage-note">Idle totals combine consecutive agent samples into episodes so the same idle period is not counted repeatedly. Totals of eight hours or more are highlighted for review.</p>
    </section>

    <?php $showRiskEmployees = true; include __DIR__ . '/partials/risk-sources.php'; ?>

    <div class="productivity-grid source-classification-grid">
        <?php foreach ([['title' => 'Top apps', 'target' => 'app', 'rows' => $topApps, 'key' => 'app_name'], ['title' => 'Top websites', 'target' => 'website', 'rows' => $topWebsites, 'key' => 'website_domain']] as $sourceGroup): ?>
            <section class="panel">
                <div class="panel-heading"><div><h2><?= e($sourceGroup['title']) ?></h2><p>Review and classify leading sources.</p></div></div>
                <div class="classified-source-list">
                    <?php foreach ($sourceGroup['rows'] as $row): ?>
                        <article>
                            <div class="source-copy"><strong><?= e($row[$sourceGroup['key']]) ?></strong><small><?= e(activity_duration((int) $row['duration_seconds'])) ?> · <?= e((int) $row['employee_count']) ?> employees</small></div>
                            <span class="classification-pill classification-<?= e($row['classification']) ?>"><?= e(ucfirst($row['classification'])) ?></span>
                            <?php if ($canManageClassifications): ?>
                                <form method="post" action="<?= e(app_url('/reports/activity/classifications')) ?>" class="quick-classify-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="match_target" value="<?= e($sourceGroup['target']) ?>">
                                    <input type="hidden" name="pattern" value="<?= e($row[$sourceGroup['key']]) ?>">
                                    <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                                    <select name="category" aria-label="Classify <?= e($row[$sourceGroup['key']]) ?>"><option value="productive">Productive</option><option value="neutral">Neutral</option><option value="distracting">Distracting</option></select>
                                    <button type="submit">Save</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($sourceGroup['rows'] === []): ?><p class="empty">No sources match this view.</p><?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <?php if ($canManageClassifications): ?>
        <details class="panel classification-manager">
            <summary><span><strong>Classification rules</strong><small>Manage the matching rules behind the score</small></span><b><?= e(count($classificationRules)) ?> rules</b></summary>
            <div class="classification-manager-body">
                <form method="post" action="<?= e(app_url('/reports/activity/classifications')) ?>" class="classification-rule-form">
                    <?= csrf_field() ?><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                    <div><label for="rule_target">Source</label><select id="rule_target" name="match_target"><option value="app">Application</option><option value="website">Website</option></select></div>
                    <div><label for="rule_pattern">Matching text</label><input id="rule_pattern" name="pattern" required placeholder="e.g. excel or example.com"></div>
                    <div><label for="rule_category">Classification</label><select id="rule_category" name="category"><option value="productive">Productive</option><option value="neutral">Neutral</option><option value="distracting">Distracting</option></select></div>
                    <button type="submit">Add rule</button>
                </form>
                <div class="classification-rule-list">
                    <?php foreach ($classificationRules as $rule): ?>
                        <article><span class="classification-pill classification-<?= e($rule['category']) ?>"><?= e(ucfirst($rule['category'])) ?></span><div><strong><?= e($rule['pattern']) ?></strong><small><?= e(ucfirst($rule['match_target'])) ?> contains this text</small></div><form method="post" action="<?= e(app_url('/reports/activity/classifications/' . $rule['id'] . '/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="return_query" value="<?= e($returnQuery) ?>"><button type="submit" class="danger-button">Remove</button></form></article>
                    <?php endforeach; ?>
                </div>
            </div>
        </details>
    <?php endif; ?>
</main>
</body>
</html>

<?php
function activity_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return $hours > 0 ? $hours . 'h ' . $minutes . 'm' : $minutes . 'm';
}

function activity_percent(int $value, int $total): string
{
    return activity_raw_percent($value, $total) . '%';
}

function activity_raw_percent(int $value, int $total): int
{
    return $total > 0 ? min(100, max(0, (int) round(($value / $total) * 100))) : 0;
}

function activity_report_url(array $filters, array $changes = []): string
{
    $query = array_merge($filters, $changes);
    $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
    return app_url('/reports/activity' . ($query === [] ? '' : '?' . http_build_query($query)));
}
?>
