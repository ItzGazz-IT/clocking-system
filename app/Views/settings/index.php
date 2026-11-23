<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container narrow">
    <div class="page-heading"><div><p class="eyebrow">Configuration</p><h1>Company settings</h1></div></div>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <section class="panel">
        <form method="post" action="<?= e(app_url('/settings')) ?>">
            <?= csrf_field() ?>
            <h2>Company details</h2>
            <label for="name">Company name</label><input id="name" name="name" value="<?= e($settings['name'] ?? '') ?>" required><?php if (isset($errors['name'])): ?><small class="error"><?= e($errors['name']) ?></small><?php endif; ?>
            <label for="phone">Phone</label><input id="phone" name="phone" value="<?= e($settings['phone'] ?? '') ?>">
            <label for="timezone">Timezone</label>
            <select id="timezone" name="timezone"><?php foreach (timezone_identifiers_list() as $timezone): ?><option value="<?= e($timezone) ?>" <?= ($settings['timezone'] ?? '') === $timezone ? 'selected' : '' ?>><?= e($timezone) ?></option><?php endforeach; ?></select>

            <h2 class="section-heading">Attendance defaults</h2>
            <div class="form-row three">
                <div><label for="default_grace_minutes">Grace minutes</label><input id="default_grace_minutes" name="default_grace_minutes" type="number" min="0" max="1440" value="<?= e($settings['attendance.default_grace_minutes'] ?? 5) ?>" required></div>
                <div><label for="default_lunch_minutes">Lunch minutes</label><input id="default_lunch_minutes" name="default_lunch_minutes" type="number" min="0" max="1440" value="<?= e($settings['attendance.default_lunch_minutes'] ?? 60) ?>" required></div>
                <div><label for="overtime_after_minutes">Overtime after</label><input id="overtime_after_minutes" name="overtime_after_minutes" type="number" min="0" max="1440" value="<?= e($settings['attendance.overtime_after_minutes'] ?? 480) ?>" required></div>
            </div>
            <div class="form-row two">
                <div><label for="absence_after_minutes">Absent after minutes</label><input id="absence_after_minutes" name="absence_after_minutes" type="number" min="0" max="1440" value="<?= e($settings['attendance.absence_after_minutes'] ?? 60) ?>" required><small class="shift-note">After shift start without clock-in confirmation.</small></div>
                <div><label for="missing_clock_out_after_minutes">Missing clock-out after</label><input id="missing_clock_out_after_minutes" name="missing_clock_out_after_minutes" type="number" min="0" max="1440" value="<?= e($settings['attendance.missing_clock_out_after_minutes'] ?? 30) ?>" required><small class="shift-note">After shift end without clock-out confirmation.</small></div>
            </div>
            <button type="submit">Save settings</button>
        </form>
    </section>
</main>
</body></html>
