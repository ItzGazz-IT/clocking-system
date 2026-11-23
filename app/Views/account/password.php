<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>
<main class="container narrow">
    <div class="page-heading"><div><p class="eyebrow">Account</p><h1>Security</h1></div></div>
    <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <section class="panel">
        <h2>Change password</h2><p class="muted">Use a unique password you do not use elsewhere.</p>
        <form method="post" action="<?= e(app_url('/account/password')) ?>">
            <?= csrf_field() ?>
            <label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required><?php if (isset($errors['current_password'])): ?><small class="error"><?= e($errors['current_password']) ?></small><?php endif; ?>
            <label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" required><?php if (isset($errors['password'])): ?><small class="error"><?= e($errors['password']) ?></small><?php endif; ?>
            <label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required><?php if (isset($errors['password_confirmation'])): ?><small class="error"><?= e($errors['password_confirmation']) ?></small><?php endif; ?>
            <button type="submit">Update password</button>
        </form>
    </section>
</main>
</body></html>

