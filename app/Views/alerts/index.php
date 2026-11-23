<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review &amp; evidence | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260723-evidence-summary')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container review-workspace">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Admin review</p>
            <h1>Review &amp; evidence</h1>
            <p class="muted">Review one day at a time. Routine activity heartbeats are kept out of the evidence log.</p>
        </div>
        <nav class="section-jump" aria-label="Page sections">
            <a href="#alert-queue">Alert queue</a>
            <a href="<?= e($showEvidence ? '#evidence-log' : review_date_url($selectedDate, $currentStatus, $currentSeverity, $currentType, $currentEventType, $selectedEmployeeId, true) . '#evidence-log') ?>">Evidence log</a>
        </nav>
    </div>

    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <section class="panel panel-heading" aria-label="Review date">
        <a class="button-quiet" href="<?= e(review_date_url($previousDate, $currentStatus, $currentSeverity, $currentType, $currentEventType, $selectedEmployeeId, $showEvidence)) ?>">← Previous day</a>
        <form method="get" class="date-navigation-form">
            <input type="hidden" name="status" value="<?= e($currentStatus === '' ? 'all' : $currentStatus) ?>">
            <?php if ($currentSeverity): ?><input type="hidden" name="severity" value="<?= e($currentSeverity) ?>"><?php endif; ?>
            <?php if ($currentType): ?><input type="hidden" name="type" value="<?= e($currentType) ?>"><?php endif; ?>
            <?php if ($currentEventType): ?><input type="hidden" name="event_type" value="<?= e($currentEventType) ?>"><?php endif; ?>
            <?php if ($selectedEmployeeId !== null): ?><input type="hidden" name="employee_id" value="<?= e($selectedEmployeeId) ?>"><?php endif; ?>
            <?php if ($showEvidence): ?><input type="hidden" name="show_evidence" value="1"><?php endif; ?>
            <label for="review_date">Showing</label>
            <input id="review_date" name="date" type="date" value="<?= e($selectedDate) ?>" max="<?= e(date('Y-m-d')) ?>" onchange="this.form.submit()">
            <strong><?= e($selectedDateLabel) ?></strong>
        </form>
        <?php if ($nextDate): ?>
            <a class="button-quiet" href="<?= e(review_date_url($nextDate, $currentStatus, $currentSeverity, $currentType, $currentEventType, $selectedEmployeeId, $showEvidence)) ?>">Next day →</a>
        <?php else: ?>
            <span class="button-quiet" aria-disabled="true">Today</span>
        <?php endif; ?>
    </section>

    <section class="review-metrics" aria-label="Review summary">
        <article>
            <span>Needs review</span>
            <strong><?= e($pendingTotal) ?></strong>
            <small>Pending alerts</small>
        </article>
        <article class="metric-danger">
            <span>High priority</span>
            <strong><?= e($highTotal) ?></strong>
            <small>Pending high-risk items</small>
        </article>
    </section>

    <details class="panel review-filter-panel review-filter-details" <?= ($currentStatus !== 'pending' || $currentSeverity || $currentType || $currentEventType || $selectedEmployeeId !== null) ? 'open' : '' ?>>
        <summary class="panel-heading">
            <div><h2>Filters</h2><p>Narrow by employee, priority, alert type or evidence type.</p></div>
            <?php if ($currentStatus !== 'pending' || $currentSeverity || $currentType || $currentEventType || $selectedEmployeeId !== null): ?>
                <a class="button-quiet" href="<?= e(app_url('/alerts?date=' . rawurlencode($selectedDate))) ?>">Reset filters</a>
            <?php endif; ?>
        </summary>
        <form method="get" class="review-filter-grid">
            <input type="hidden" name="date" value="<?= e($selectedDate) ?>">
            <?php if ($showEvidence): ?><input type="hidden" name="show_evidence" value="1"><?php endif; ?>
            <div>
                <label for="employee_id">Employee</label>
                <select id="employee_id" name="employee_id">
                    <option value="">All employees</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= e($employee['id']) ?>" <?= $selectedEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>>
                            <?= e($employee['employee_number'] . ' · ' . $employee['first_name'] . ' ' . $employee['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status">Alert status</label>
                <select id="status" name="status">
                    <option value="pending" <?= $currentStatus === 'pending' ? 'selected' : '' ?>>Needs review</option>
                    <option value="all" <?= $currentStatus === '' ? 'selected' : '' ?>>All statuses</option>
                    <option value="escalated" <?= $currentStatus === 'escalated' ? 'selected' : '' ?>>Escalated</option>
                    <option value="dismissed" <?= $currentStatus === 'dismissed' ? 'selected' : '' ?>>Dismissed</option>
                </select>
            </div>
            <div>
                <label for="severity">Priority</label>
                <select id="severity" name="severity">
                    <option value="">All priorities</option>
                    <option value="high" <?= $currentSeverity === 'high' ? 'selected' : '' ?>>High</option>
                    <option value="medium" <?= $currentSeverity === 'medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="low" <?= $currentSeverity === 'low' ? 'selected' : '' ?>>Low</option>
                </select>
            </div>
            <div>
                <label for="type">Alert type</label>
                <select id="type" name="type">
                    <option value="">All alert types</option>
                    <?php foreach ($types as $alertType): ?>
                        <option value="<?= e($alertType) ?>" <?= $currentType === $alertType ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $alertType))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="event_type">Evidence type</label>
                <select id="event_type" name="event_type">
                    <option value="">All evidence types</option>
                    <?php foreach ($eventTypes as $eventType): ?>
                        <option value="<?= e($eventType['code']) ?>" <?= $currentEventType === $eventType['code'] ? 'selected' : '' ?>><?= e($eventType['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Apply filters</button>
        </form>
    </details>

    <section class="panel review-table-panel" id="alert-queue">
        <div class="review-section-heading">
            <div>
                <span class="section-icon section-icon-alert" aria-hidden="true">!</span>
                <div><p class="eyebrow">Action required</p><h2>Alert queue</h2></div>
            </div>
            <span class="result-count"><?= e($total) ?> result<?= $total === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Priority</th><th>Employee</th><th>Issue</th><th>Detected</th><th>Status</th><th><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
            <?php foreach ($alerts as $alert): ?>
                <tr>
                    <td><span class="badge <?= e($alert['severity'] === 'high' ? 'badge-danger' : ($alert['severity'] === 'medium' ? 'badge-warning' : '')) ?>"><?= e(ucfirst($alert['severity'])) ?></span></td>
                    <td><strong><?= e($alert['employee_name']) ?></strong><small><?= e($alert['employee_number']) ?></small></td>
                    <td class="issue-cell"><strong><?= e(ucwords(str_replace('_', ' ', $alert['alert_type']))) ?></strong><small><?= e($alert['description']) ?></small></td>
                    <td><strong><?= e(local_datetime($alert['created_at'], 'd M H:i')) ?></strong><small>SAST</small></td>
                    <td><span class="status-pill status-<?= e($alert['status']) ?>"><?= e($alert['status'] === 'pending' ? 'Needs review' : ucfirst($alert['status'])) ?></span></td>
                    <td><a class="button-quiet" href="<?= e(app_url('/alerts/' . $alert['id'])) ?>">Review</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($alerts === []): ?><tr><td colspan="6" class="empty"><strong>No matching alerts</strong><small>Adjust the filters or enjoy the quiet.</small></td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Alert pages">
                <?php if ($page > 1): ?><a class="button-quiet" href="<?= e(review_page_url($page - 1, $currentStatus, $currentSeverity, $currentType, $currentEventType, $selectedEmployeeId, $selectedDate)) ?>#alert-queue">← Previous</a><?php endif; ?>
                <span>Page <?= e($page) ?> of <?= e($totalPages) ?></span>
                <?php if ($page < $totalPages): ?><a class="button-quiet" href="<?= e(review_page_url($page + 1, $currentStatus, $currentSeverity, $currentType, $currentEventType, $selectedEmployeeId, $selectedDate)) ?>#alert-queue">Next →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <section class="panel review-table-panel" id="evidence-log">
        <div class="review-section-heading">
            <div>
                <span class="section-icon section-icon-evidence" aria-hidden="true">⌁</span>
                <div><p class="eyebrow">Supporting activity</p><h2>Evidence log</h2></div>
            </div>
            <?php if ($showEvidence): ?>
                <a class="button-quiet" href="<?= e(review_date_url($selectedDate, $currentStatus, $currentSeverity, $currentType, $currentEventType, $selectedEmployeeId, false)) ?>#alert-queue">Hide evidence</a>
            <?php else: ?>
                <span class="result-count"><?= e($evidenceToday) ?> important events</span>
            <?php endif; ?>
        </div>
        <?php if (!$showEvidence): ?>
            <div class="evidence-collapsed">
                <p>Routine keyboard/mouse heartbeats and repeated idle samples are hidden. Evidence contains meaningful Windows session changes for this day.</p>
                <a class="button-quiet" href="<?= e(review_date_url($selectedDate, $currentStatus, $currentSeverity, $currentType, $currentEventType, $selectedEmployeeId, true)) ?>#evidence-log">Show supporting evidence</a>
            </div>
        <?php else: ?>
        <?php if ($trackingEvidence !== []): ?>
            <div class="tracking-summary">
                <div class="tracking-summary-heading"><span>Automatic tracking</span><small><?= e(count($trackingEvidence)) ?> employee<?= count($trackingEvidence) === 1 ? '' : 's' ?></small></div>
                <div class="tracking-summary-grid">
                <?php foreach ($trackingEvidence as $summary): ?>
                    <?php $attendance = $summary['attendance']; $activity = $summary['activity']; ?>
                    <article class="tracking-summary-card">
                        <header><strong><?= e($summary['employee_name']) ?></strong><?php if ($attendance): ?><span><?= e(ucfirst((string) $attendance['overtime_status'])) ?></span><?php endif; ?></header>
                        <div class="tracking-summary-metrics">
                            <?php if ($attendance): ?>
                                <div><small>Active</small><b><?= e(format_review_idle((int) $attendance['active_seconds'])) ?></b></div>
                                <div><small>Inactive</small><b><?= e(format_review_idle((int) $attendance['inactive_seconds'])) ?></b></div>
                            <?php endif; ?>
                            <?php if ($activity): ?>
                                <div><small>Tracked</small><b><?= e(format_review_idle((int) $activity['tracked_seconds'])) ?></b></div>
                                <div><small>Sources</small><b><?= e((int) $activity['app_count'] + (int) $activity['website_count']) ?></b></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($activity): ?><p><?= e((int) $activity['slice_count']) ?> samples · <?= e((int) $activity['app_count']) ?> apps · <?= e((int) $activity['website_count']) ?> websites</p><?php endif; ?>
                        <footer><?php if ($attendance): ?>Workday <?= e(local_datetime((string) $attendance['opened_at'], 'H:i')) ?>–<?= e($attendance['closed_at'] ? local_datetime((string) $attendance['closed_at'], 'H:i') : 'now') ?><?php elseif ($activity): ?>Activity <?= e(local_datetime((string) $activity['first_activity_at'], 'H:i')) ?>–<?= e(local_datetime((string) $activity['last_activity_at'], 'H:i')) ?><?php endif; ?>
                            <?php if ($attendance && (int) $attendance['overtime_minutes'] > 0): ?><span><?= e(format_minutes((int) $attendance['overtime_minutes'])) ?> overtime</span><?php endif; ?></footer>
                    </article>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($latestIdle): ?>
            <div class="evidence-context">
                <span>Latest idle reading</span>
                <p><strong><?= e($latestIdle['employee_name'] ?? 'Unassigned employee') ?></strong> on <?= e($latestIdle['hostname'] ?? 'unknown device') ?> was idle for <strong><?= e(format_review_idle((int) $latestIdle['idle_seconds'])) ?></strong> at <?= e(local_datetime((string) $latestIdle['occurred_at'])) ?>.</p>
            </div>
        <?php endif; ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Occurred</th><th>Event</th><th>Employee</th><th>Device</th><th>Idle</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <?php $metadata = json_decode((string) ($event['metadata'] ?? '{}'), true) ?: []; ?>
                <tr>
                    <td><strong><?= e(local_datetime((string) $event['occurred_at'])) ?></strong><small>Received <?= e(local_datetime((string) $event['received_at'])) ?></small></td>
                    <td><span class="event-pill event-<?= e($event['code']) ?>"><?= e(review_event_label((string) $event['code'])) ?></span></td>
                    <td><strong><?= e($event['employee_name'] ?? 'Unassigned') ?></strong></td>
                    <td><strong><?= e($event['device_name'] ?? 'Unknown') ?></strong><small><?= e($event['hostname'] ?? '—') ?></small></td>
                    <td><?= $event['idle_seconds'] === null ? '—' : e(format_review_idle((int) $event['idle_seconds'])) ?></td>
                    <td><?= e($metadata['reason'] ?? $metadata['source'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($events === []): ?><tr><td colspan="6" class="empty"><strong>No matching evidence</strong><small>No Windows activity fits the current filters.</small></td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>

<?php
function format_review_idle(int $seconds): string
{
    if ($seconds >= 3600) {
        return sprintf('%dh %dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
    if ($seconds >= 60) {
        return sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60);
    }
    return $seconds . 's';
}

function review_event_label(string $code): string
{
    return match ($code) {
        'idle' => 'Idle', 'login' => 'Login', 'logout' => 'Logout', 'lock' => 'Locked',
        'unlock' => 'Unlocked', 'sleep' => 'Sleep', 'wake' => 'Wake',
        'shutdown' => 'Shutdown', 'restart' => 'Restart', default => ucfirst($code),
    };
}

function review_page_url(int $page, string $status, ?string $severity, ?string $type, string $eventType, ?int $employeeId, string $date): string
{
    return app_url('/alerts?' . http_build_query(array_filter([
        'status' => $status === '' ? 'all' : $status,
        'severity' => $severity,
        'type' => $type,
        'event_type' => $eventType,
        'employee_id' => $employeeId,
        'date' => $date,
        'page' => $page,
    ], static fn ($value): bool => $value !== null && $value !== '')));
}

function review_date_url(string $date, string $status, ?string $severity, ?string $type, string $eventType, ?int $employeeId, bool $showEvidence): string
{
    return app_url('/alerts?' . http_build_query(array_filter([
        'date' => $date,
        'status' => $status === '' ? 'all' : $status,
        'severity' => $severity,
        'type' => $type,
        'event_type' => $eventType,
        'employee_id' => $employeeId,
        'show_evidence' => $showEvidence ? '1' : null,
    ], static fn ($value): bool => $value !== null && $value !== '')));
}
?>
