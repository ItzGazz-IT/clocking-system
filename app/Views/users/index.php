<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User accounts | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container">
    <div class="page-heading"><div><p class="eyebrow">Access control</p><h1>User accounts</h1></div><p class="muted"><?= count($accounts) ?> provisioned accounts</p></div>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <?php if ($temporaryPassword): ?>
        <section class="credential-card" aria-label="Temporary credentials">
            <div><p class="eyebrow">Shown once</p><h2>Temporary sign-in</h2><p>Ask the user to change this password immediately after signing in.</p></div>
            <dl><div><dt>Username</dt><dd><?= e($temporaryUsername) ?></dd></div><div><dt>Temporary password</dt><dd><code><?= e($temporaryPassword) ?></code></dd></div></dl>
        </section>
    <?php endif; ?>
    <?php if (!empty($passwordResetRequests)): ?>
        <section class="panel table-panel reset-requests">
            <h2>Password reset requests</h2>
            <div class="table-wrap"><table><thead><tr><th>User</th><th>Requested</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($passwordResetRequests as $request): ?>
                <tr>
                    <td><strong><?= e($request['display_name'] ?: $request['username']) ?></strong><small><?= e($request['username']) ?></small></td>
                    <td><?= e(local_datetime($request['requested_at'])) ?></td>
                    <td>
                        <?php if ($request['user_id']): ?>
                            <form method="post" action="<?= e(app_url('/users/' . $request['user_id'] . '/reset-password')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="username" value="<?= e($request['username']) ?>">
                                <button class="button-quiet" type="submit">Reset password</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-warning">Unknown username</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
    <div class="content-grid">
        <section class="panel">
            <h2>Provision employee access</h2>
            <?php if ($employees === []): ?><p class="empty">Every active employee already has an account.</p><?php else: ?>
            <form method="post" action="<?= e(app_url('/users')) ?>">
                <?= csrf_field() ?>
                <label for="employee_id">Employee</label>
                <select id="employee_id" name="employee_id" required><option value="">Choose an employee</option><?php foreach ($employees as $employee): ?><option value="<?= e($employee['id']) ?>" data-username="<?= e($employee['employee_number']) ?>"><?= e($employee['employee_number'] . ' · ' . $employee['first_name'] . ' ' . $employee['last_name']) ?></option><?php endforeach; ?></select>
                <?php if (isset($errors['employee_id'])): ?><small class="error"><?= e($errors['employee_id']) ?></small><?php endif; ?>
                <label for="username">Username</label><input id="username" name="username" maxlength="80" value="<?= e($old['username'] ?? '') ?>" required><?php if (isset($errors['username'])): ?><small class="error"><?= e($errors['username']) ?></small><?php endif; ?>
                <label for="role_id">Access role</label><select id="role_id" name="role_id" required><option value="">Choose a role</option><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select>
                <button type="submit">Create user account</button>
            </form>
            <?php endif; ?>
        </section>
        <section class="panel table-panel">
            <h2>Access directory</h2>
            <div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Security</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td><strong><?= e($account['display_name']) ?></strong><small><?= e($account['username'] ?: $account['employee_number']) ?><?= $account['employee_number'] ? ' · ' . e($account['employee_number']) : '' ?></small></td>
                    <td><form class="inline-role-form" method="post" action="<?= e(app_url('/users/' . $account['id'] . '/role')) ?>"><?= csrf_field() ?><select name="role_id" aria-label="Role for <?= e($account['display_name']) ?>"><?php foreach($roles as $role):?><option value="<?= e($role['id']) ?>" <?= (int)$account['system_role_id']===(int)$role['id']?'selected':'' ?>><?= e($role['name']) ?></option><?php endforeach;?></select><button class="button-quiet" type="submit">Save</button></form></td>
                    <td><span class="badge <?= !$account['is_active'] ? 'badge-danger' : ($account['must_change_password'] ? 'badge-warning' : '') ?>"><?= !$account['is_active'] ? 'Disabled' : ($account['must_change_password'] ? 'Change required' : 'Active') ?></span><small><?= $account['last_login_at'] ? 'Last login ' . e(local_datetime($account['last_login_at'], 'd M Y, H:i')) : 'Never signed in' ?></small></td>
                    <td><div class="table-actions">
                        <form method="post" action="<?= e(app_url('/users/' . $account['id'] . '/reset-password')) ?>"><?= csrf_field() ?><input type="hidden" name="username" value="<?= e($account['username']) ?>"><button class="button-quiet" type="submit">Reset</button></form>
                        <form method="post" action="<?= e(app_url('/users/' . $account['id'] . '/toggle')) ?>"><?= csrf_field() ?><button class="button-quiet <?= $account['is_active'] ? 'danger' : '' ?>" type="submit"><?= $account['is_active'] ? 'Disable' : 'Enable' ?></button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
</main>
</body></html>
