<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(config('app.url')) ?>/assets/css/app.css?v=20260915-auto-dashboard">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<?php
$setupChecklist = $setupChecklist ?? [];
$completeSetup = count(array_filter($setupChecklist, static fn ($item): bool => $item['complete']));
$setupTotal = count($setupChecklist);
$appVersion = $appVersion ?? (string) config('app.version', '1.0.1');
$agentVersion = $agentVersion ?? (string) config('app.agent_version', '1.0.1');
$isAdminView = $user->can('attendance.view.all');
$dashboardTime = static function (?string $at, ?string $timezone, string $format = 'H:i'): string {
    return $at ? (new DateTimeImmutable($at, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone ?: (string) config('app.timezone', 'Africa/Johannesburg')))->format($format) : '—';
};
?>
<main class="container dashboard-page">
    <div class="page-heading compact-heading">
        <div>
            <p class="eyebrow"><?= $isAdminView ? 'Today' : 'My day' ?></p>
            <h1>Good day, <?= e($user?->name) ?></h1>
        </div>
        <div class="dashboard-update"><p class="muted" data-dashboard-date><?= e(date('D, d M Y')) ?></p><small data-dashboard-refresh role="status">Updated <?= e(date('H:i')) ?> · refreshes every minute</small></div>
    </div>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

    <section class="dashboard-snapshot" id="dashboard-snapshot" data-dashboard-live aria-label="Today's automatic attendance snapshot">
        <?php if ($statusUnavailable ?? false): ?>
            <article class="dashboard-unavailable"><span>Attendance unavailable</span><p>Current figures could not be loaded. The dashboard will retry automatically.</p></article>
        <?php else: ?>
            <article class="primary"><span>Working now</span><strong><?= e($metrics['working']) ?></strong><small><?= e($metrics['employees']) ?> employee<?= $metrics['employees'] === 1 ? '' : 's' ?> in view</small></article>
            <article><span>Recorded work</span><strong><?= e(format_minutes($metrics['today_minutes'])) ?></strong><small>Today · employee local time</small></article>
            <article><span>Inactive time</span><strong><?= e(format_minutes($metrics['inactive_minutes'])) ?></strong><small>Between work periods</small></article>
            <article><span>Inactive now</span><strong><?= e($metrics['inactive']) ?></strong><small>Idle, locked or signed out</small></article>
            <article><span>Not started</span><strong><?= e($metrics['not_started']) ?></strong><small>No work recorded today</small></article>
            <article><span>Needs attention</span><strong><?= e($metrics['needs_attention']) ?></strong><small>Agent connection or setup</small></article>
        <?php endif; ?>
    </section>
    <?php if ($isAdminView || $user->can('attendance.view.department')): ?>
        <section class="panel team-status-panel" id="dashboard-team" data-dashboard-live>
            <div class="panel-heading">
                <div><p class="eyebrow">Team status</p><h2>Employees today</h2></div>
                <a class="button-quiet" href="<?= e(app_url('/attendance/history')) ?>">Attendance</a>
            </div>
            <?php if ($statusUnavailable ?? false): ?><p class="empty">Employee status is temporarily unavailable.</p>
            <?php elseif (($employeeStatusCards ?? []) === []): ?><p class="empty">No employees are available in your view.</p><?php endif; ?>
            <div class="team-status-grid">
                <?php foreach ($employeeStatusCards as $card): ?>
                    <article class="team-status-card <?= e($card['status_tone']) ?>">
                        <a class="team-status-main" href="<?= e(app_url('/attendance/history?employee_id=' . $card['id'])) ?>">
                            <span class="status-dot"></span>
                            <div>
                                <strong><?= e($card['employee_name']) ?></strong>
                                <small><?= e($card['employee_number']) ?><?= $card['department_name'] ? ' · ' . e($card['department_name']) : '' ?></small>
                            </div>
                            <b><?= e($card['status_label']) ?></b>
                        </a>
                        <dl>
                            <div><dt>First activity</dt><dd><?= e($dashboardTime($card['first_in'], $card['timezone'])) ?></dd></div>
                            <div><dt>Latest work</dt><dd><?= e($dashboardTime($card['last_work_at'], $card['timezone'])) ?></dd></div>
                            <div><dt>Work</dt><dd><?= e(format_minutes($card['work_minutes'])) ?></dd></div>
                            <div><dt>Inactive</dt><dd><?= e(format_minutes($card['break_minutes'])) ?></dd></div>
                        </dl>
                        <p class="team-status-note"><?= e($card['status_note'] ?: ($card['work_date'] . ' · ' . $card['timezone'])) ?>
                            <?php if ($card['latest_event_at']): ?><span>Last observation <?= e($dashboardTime($card['latest_event_at'], $card['timezone'], 'd M, H:i')) ?></span><?php endif; ?>
                        </p>
                        <div class="team-status-actions"><a href="<?= e(app_url('/reports/employees/' . $card['id'] . '/activity')) ?>">Activity report</a><a href="<?= e(app_url('/attendance/history?employee_id=' . $card['id'])) ?>">Attendance</a></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="dashboard-main" id="dashboard-details" data-dashboard-live>
        <section class="panel clock-panel">
            <div class="panel-heading">
                <div><p class="eyebrow">Flexible time</p><h2><?= $selfService ? 'My automatic attendance' : 'Automatic attendance' ?></h2></div>
            </div>
            <p>Attendance is recorded automatically from computer activity. There is nothing to clock in or confirm.</p>
            <?php if ($selfService && ($employeeStatusCards ?? []) !== []): ?>
                <?php $myCard = $employeeStatusCards[0]; ?>
                <p><strong><?= e($myCard['status_label']) ?></strong> · <?= e(format_minutes($myCard['work_minutes'])) ?> recorded today</p>
                <p class="muted">First activity <?= e($dashboardTime($myCard['first_in'], $myCard['timezone'])) ?> · Latest work <?= e($dashboardTime($myCard['last_work_at'], $myCard['timezone'])) ?></p>
                <?php if ($myCard['status_note']): ?><p class="muted"><?= e($myCard['status_note']) ?></p><?php endif; ?>
            <?php endif; ?>
            <p class="muted">Each day runs from midnight to midnight in the employee's local timezone. A PC left on overnight does not count as working.</p>
            <p class="muted">Inactive time covers gaps between work periods. Lunch and other breaks are not labelled separately. Live totals settle as activity arrives and inactivity thresholds are reached.</p>
            <?php if ($employees === []): ?><p class="empty">No employees are available in your view.</p><?php endif; ?>
        </section>
        <section class="panel compact-activity">
            <div class="panel-heading">
                <div><p class="eyebrow">Latest</p><h2>Agent activity</h2></div>
                <?php if ($user?->employeeId): ?><a class="button-quiet" href="<?= e(app_url('/attendance/history')) ?>">History</a><?php endif; ?>
            </div>
            <?php if ($activityUnavailable ?? false): ?><p class="empty">Agent activity is temporarily unavailable.</p>
            <?php elseif ($activity === []): ?><p class="empty">No agent observations have arrived yet.</p><?php endif; ?>
            <div class="activity-list compact">
                <?php foreach (array_slice($activity, 0, 4) as $item): ?>
                    <article><span class="activity-icon"></span><div><strong><?= e($item['employee_name']) ?></strong><p><?= e($item['event_name']) ?> · <?= e($dashboardTime($item['occurred_at'], $item['timezone'], 'd M, H:i')) ?></p></div></article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="quick-links">
        <a href="<?= e(app_url('/windows-app/download')) ?>">
            <strong>Windows app</strong>
            <span>Agent <?= e($agentVersion) ?></span>
        </a>
        <?php if ($isAdminView): ?>
            <a href="<?= e(app_url('/reports/weekly')) ?>"><strong>Attendance report</strong><span>Weekly export</span></a>
        <?php elseif ($user?->employeeId): ?>
            <a href="<?= e(app_url('/my/productivity')) ?>"><strong>My productivity</strong><span>Focus and app time</span></a>
            <a href="<?= e(app_url('/attendance/history')) ?>"><strong>My attendance</strong><span>Daily records</span></a>
        <?php endif; ?>
        <?php if ($user->can('settings.manage')): ?>
            <a href="<?= e(app_url('/settings')) ?>"><strong>Setup</strong><span><?= e((string) $completeSetup) ?> / <?= e((string) $setupTotal) ?> complete</span></a>
        <?php endif; ?>
    </section>
</main>
<script defer src="<?= e(app_url('/assets/js/dashboard.js?v=20260915-auto')) ?>"></script>
</body>
</html>
