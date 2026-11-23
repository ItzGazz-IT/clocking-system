<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Departments | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container">
    <div class="page-heading"><div><p class="eyebrow">Organisation</p><h1>Departments</h1></div><p class="muted"><?= count($departments) ?> departments</p></div>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <div class="content-grid <?= !$user->can('departments.manage') ? 'single-column' : '' ?>">
        <?php if ($user->can('departments.manage')): ?>
        <section class="panel">
            <h2>Add department</h2>
            <form method="post" action="<?= e(app_url('/departments')) ?>">
                <?= csrf_field() ?>
                <label for="code">Code</label>
                <input id="code" name="code" maxlength="40" value="<?= e($old['code'] ?? '') ?>" required>
                <?php if (isset($errors['code'])): ?><small class="error"><?= e($errors['code']) ?></small><?php endif; ?>
                <label for="name">Name</label>
                <input id="name" name="name" maxlength="120" value="<?= e($old['name'] ?? '') ?>" required>
                <?php if (isset($errors['name'])): ?><small class="error"><?= e($errors['name']) ?></small><?php endif; ?>
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3"><?= e($old['description'] ?? '') ?></textarea>
                <button type="submit">Create department</button>
            </form>
        </section>
        <?php endif; ?>
        <section class="panel table-panel">
            <h2>Department directory</h2>
            <div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Employees</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($departments as $department): ?>
                <tr><td><span class="code"><?= e($department['code']) ?></span></td><td><strong><?= e($department['name']) ?></strong><small><?= e($department['description']) ?></small></td><td><?= e($department['employee_count']) ?></td><td><span class="badge"><?= $department['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><?php if($user->can('departments.manage')):?><div class="table-actions"><a class="button-quiet" href="<?= e(app_url('/departments/' . $department['id'] . '/edit')) ?>">Edit</a><form method="post" action="<?= e(app_url('/departments/' . $department['id'] . '/archive')) ?>"><?= csrf_field() ?><button class="button-quiet danger" type="submit">Archive</button></form></div><?php else: ?>—<?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if ($departments === []): ?><tr><td colspan="5" class="empty">No departments yet.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </div>
</main>
</body></html>
