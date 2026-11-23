<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shifts | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container">
    <div class="page-heading"><div><p class="eyebrow">Scheduling</p><h1>Shifts</h1></div><p class="muted">Rules stay configurable</p></div>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <div class="content-grid <?= !$user->can('shifts.manage') ? 'single-column' : '' ?>">
        <?php if ($user->can('shifts.manage')): ?>
        <section class="panel">
            <h2>Add shift</h2>
            <form method="post" action="<?= e(app_url('/shifts')) ?>">
                <?= csrf_field() ?>
                <div class="form-row"><div><label for="code">Code</label><input id="code" name="code" maxlength="40" value="<?= e($old['code'] ?? '') ?>" required><?php if (isset($errors['code'])): ?><small class="error"><?= e($errors['code']) ?></small><?php endif; ?></div>
                <div><label for="name">Name</label><input id="name" name="name" maxlength="120" value="<?= e($old['name'] ?? '') ?>" required><?php if (isset($errors['name'])): ?><small class="error"><?= e($errors['name']) ?></small><?php endif; ?></div></div>
                <div class="form-row"><div><label for="start_time">Start</label><input id="start_time" name="start_time" type="time" value="<?= e($old['start_time'] ?? '08:00') ?>" required></div>
                <div><label for="end_time">End</label><input id="end_time" name="end_time" type="time" value="<?= e($old['end_time'] ?? '17:00') ?>" required></div></div>
                <div class="form-row three"><div><label for="paid_minutes">Paid minutes</label><input id="paid_minutes" name="paid_minutes" type="number" min="0" max="1440" value="<?= e($old['paid_minutes'] ?? '480') ?>" required></div>
                <div><label for="grace_period_minutes">Grace</label><input id="grace_period_minutes" name="grace_period_minutes" type="number" min="0" max="1440" value="<?= e($old['grace_period_minutes'] ?? '5') ?>" required></div>
                <div><label for="expected_lunch_minutes">Lunch</label><input id="expected_lunch_minutes" name="expected_lunch_minutes" type="number" min="0" max="1440" value="<?= e($old['expected_lunch_minutes'] ?? '60') ?>" required></div></div>
                <button type="submit">Create shift</button>
            </form>
        </section>
        <?php endif; ?>
        <section class="panel table-panel">
            <h2>Shift directory</h2>
            <div class="table-wrap"><table><thead><tr><th>Shift</th><th>Hours</th><th>Grace</th><th>Employees</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($shifts as $shift): ?>
                <tr><td><strong><?= e($shift['name']) ?></strong><small><?= e($shift['code']) ?></small></td><td><?= e(substr($shift['start_time'], 0, 5)) ?>–<?= e(substr($shift['end_time'], 0, 5)) ?></td><td><?= e($shift['grace_period_minutes']) ?> min</td><td><?= e($shift['employee_count']) ?></td><td><?php if($user->can('shifts.manage')):?><div class="table-actions"><a class="button-quiet" href="<?= e(app_url('/shifts/' . $shift['id'] . '/edit')) ?>">Edit</a><form method="post" action="<?= e(app_url('/shifts/' . $shift['id'] . '/archive')) ?>"><?= csrf_field() ?><button class="button-quiet danger" type="submit">Archive</button></form></div><?php else: ?>—<?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if ($shifts === []): ?><tr><td colspan="5" class="empty">No shifts yet.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </div>
</main>
</body></html>
