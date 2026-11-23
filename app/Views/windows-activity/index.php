<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Windows activity | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>

<main class="container">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Windows telemetry</p>
            <h1>Windows activity</h1>
        </div>
        <p class="muted">Idle, lock, unlock, sleep, wake, logout and shutdown events</p>
    </div>

    <section class="panel windows-filter">
        <form method="get">
            <div class="form-row">
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
                <div class="filter-actions">
                    <button type="submit">Apply filter</button>
                    <?php if ($selectedEmployeeId !== null): ?>
                        <a class="button-quiet" href="<?= e(app_url('/windows-activity')) ?>">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </section>

    <section class="metrics" aria-label="Windows activity metrics">
        <article><span>Idle samples today</span><strong><?= e($counts['idle'] ?? 0) ?></strong></article>
        <article><span>Locks today</span><strong><?= e($counts['lock'] ?? 0) ?></strong></article>
        <article><span>Unlocks today</span><strong><?= e($counts['unlock'] ?? 0) ?></strong></article>
        <article><span>Sleep events</span><strong><?= e($counts['sleep'] ?? 0) ?></strong></article>
        <article><span>Wake events</span><strong><?= e($counts['wake'] ?? 0) ?></strong></article>
        <article>
            <span>Latest idle</span>
            <strong><?= $latestIdle ? e(format_idle((int) $latestIdle['idle_seconds'])) : '—' ?></strong>
        </article>
    </section>

    <?php if ($latestIdle): ?>
        <section class="panel windows-summary">
            <h2>Latest idle reading</h2>
            <p>
                <strong><?= e($latestIdle['employee_name'] ?? 'Unassigned employee') ?></strong>
                on <strong><?= e($latestIdle['hostname'] ?? 'Unknown device') ?></strong>
                was idle for <strong><?= e(format_idle((int) $latestIdle['idle_seconds'])) ?></strong>
                at <?= e(local_datetime((string) $latestIdle['occurred_at'])) ?>.
            </p>
        </section>
    <?php endif; ?>

    <section class="panel table-panel">
        <h2>Recent Windows events</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>Employee</th>
                    <th>Device</th>
                    <th>Idle</th>
                    <th>Details</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <?php $metadata = json_decode((string) ($event['metadata'] ?? '{}'), true) ?: []; ?>
                    <tr>
                        <td>
                            <strong><?= e(local_datetime((string) $event['occurred_at'])) ?></strong>
                            <small>Received <?= e(local_datetime((string) $event['received_at'])) ?></small>
                        </td>
                        <td><span class="badge"><?= e(event_label((string) $event['code'])) ?></span></td>
                        <td><?= e($event['employee_name'] ?? '—') ?></td>
                        <td>
                            <strong><?= e($event['device_name'] ?? '—') ?></strong>
                            <small><?= e($event['hostname'] ?? '—') ?></small>
                        </td>
                        <td><?= $event['idle_seconds'] === null ? '—' : e(format_idle((int) $event['idle_seconds'])) ?></td>
                        <td>
                            <?= e($metadata['reason'] ?? $metadata['source'] ?? '—') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ($events === []): ?>
                    <tr><td colspan="6" class="empty">No Windows activity has been received yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>

<?php
function format_idle(int $seconds): string
{
    if ($seconds >= 3600) {
        return sprintf('%dh %dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
    if ($seconds >= 60) {
        return sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60);
    }
    return $seconds . 's';
}

function event_label(string $code): string
{
    return match ($code) {
        'idle' => 'Idle',
        'login' => 'Login',
        'logout' => 'Logout',
        'lock' => 'Locked',
        'unlock' => 'Unlocked',
        'sleep' => 'Sleep',
        'wake' => 'Wake',
        'shutdown' => 'Shutdown',
        'restart' => 'Restart',
        default => ucfirst($code),
    };
}
?>
