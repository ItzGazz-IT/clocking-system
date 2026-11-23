<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employees | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container">
    <div class="page-heading"><div><p class="eyebrow">People</p><h1>Employees</h1></div><p class="muted"><?= count($employees) ?> employee records</p></div>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <div class="content-grid employee-grid <?= !$user->can('employees.manage') ? 'single-column' : '' ?>">
        <?php if ($user->can('employees.manage')): ?>
        <section class="panel">
            <h2>Add employee</h2>
            <?php if ($departments === []): ?><div class="alert error">Create at least one department first.</div><?php endif; ?>
            <form method="post" action="<?= e(app_url('/employees')) ?>">
                <?= csrf_field() ?>
                <label for="employee_number">Employee number</label><input id="employee_number" name="employee_number" maxlength="50" value="<?= e($old['employee_number'] ?? '') ?>" required>
                <?php if (isset($errors['employee_number'])): ?><small class="error"><?= e($errors['employee_number']) ?></small><?php endif; ?>
                <div class="form-row"><div><label for="first_name">First name</label><input id="first_name" name="first_name" value="<?= e($old['first_name'] ?? '') ?>" required></div><div><label for="last_name">Last name</label><input id="last_name" name="last_name" value="<?= e($old['last_name'] ?? '') ?>" required></div></div>
                <label for="department_id">Department</label><select id="department_id" name="department_id" required><option value="">Choose</option><?php foreach ($departments as $department): ?><option value="<?= e($department['id']) ?>"><?= e($department['name']) ?></option><?php endforeach; ?></select>
                <div class="form-row"><div><label for="hire_date">Hire date</label><input id="hire_date" name="hire_date" type="date" value="<?= e($old['hire_date'] ?? date('Y-m-d')) ?>"></div><div><label for="employment_type">Type</label><select id="employment_type" name="employment_type"><option value="full_time">Full time</option><option value="part_time">Part time</option><option value="contractor">Contractor</option></select></div></div>
                <input type="hidden" name="attendance_mode" value="flexible">
                <div class="alert success">Flexible automatic activity tracking · no shift required</div>
                <div class="form-row"><div><label for="activity_attendance_effective_from">Activity mode starts</label><input id="activity_attendance_effective_from" name="activity_attendance_effective_from" type="date" value="<?= e($old['activity_attendance_effective_from'] ?? date('Y-m-d')) ?>"></div><div><label for="daily_target_minutes">Daily target</label><input id="daily_target_minutes" name="daily_target_minutes" type="number" min="1" max="1440" value="<?= e($old['daily_target_minutes'] ?? 480) ?>"><small>480 minutes = 8 hours</small></div></div>
                <div class="form-row"><div><label for="idle_threshold_minutes">Idle threshold</label><input id="idle_threshold_minutes" name="idle_threshold_minutes" type="number" min="1" max="240" value="<?= e($old['idle_threshold_minutes'] ?? 30) ?>"></div><div><label for="offline_timeout_minutes">Offline timeout</label><input id="offline_timeout_minutes" name="offline_timeout_minutes" type="number" min="1" max="240" value="<?= e($old['offline_timeout_minutes'] ?? 15) ?>"></div></div>
                <button type="submit" <?= $departments === [] ? 'disabled' : '' ?>>Create employee</button>
            </form>
        </section>
        <?php endif; ?>
        <section class="panel table-panel">
            <h2>Employee directory</h2>
            <div class="table-wrap"><table><thead><tr><th>Employee</th><th>Department</th><th>Shift</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($employees as $employee): ?><tr><td><strong><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></strong><small><?= e($employee['employee_number']) ?></small></td><td><?= e($employee['department_name'] ?? '—') ?></td><td><?= e($employee['attendance_mode'] === 'flexible' ? 'Flexible activity' : ($employee['shift_name'] ?? '—')) ?></td><td><span class="badge"><?= e(ucfirst($employee['employment_status'])) ?></span></td><td><div class="table-actions"><?php if($user->can('attendance.history.view')):?><a class="button-quiet" href="<?= e(app_url('/attendance/history?employee_id=' . $employee['id'])) ?>">Attendance</a><?php endif; ?><?php if($user->can('employees.manage')):?><a class="button-quiet" href="<?= e(app_url('/employees/' . $employee['id'] . '/edit')) ?>">Edit</a><form method="post" action="<?= e(app_url('/employees/' . $employee['id'] . '/archive')) ?>"><?= csrf_field() ?><button class="button-quiet danger" type="submit">Archive</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
            <?php if ($employees === []): ?><tr><td colspan="5" class="empty">No employees yet.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </div>
</main>
</body></html>
