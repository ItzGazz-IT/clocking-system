<!doctype html>
<html lang="en-ZA">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password | <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= e(app_url('/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(config('app.url')) ?>/assets/css/app.css?v=20260710-daily-attendance">
</head>
<body>
<main class="auth-shell">
    <section class="auth-card" aria-labelledby="forgot-title">
        <p class="eyebrow">Account help</p>
        <h1 id="forgot-title">Forgot password</h1>
        <p class="muted">Enter your username. An administrator will reset your password and give you a temporary one.</p>
        <?php if (!empty($flash)): ?>
            <div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(app_url('/forgot-password')) ?>">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <input id="username" name="username" maxlength="80" autocomplete="username" value="<?= e(old('username')) ?>" required>
            <?php if (!empty($errors['username'])): ?><small class="error"><?= e($errors['username']) ?></small><?php endif; ?>
            <button type="submit">Request password reset</button>
        </form>
        <a class="auth-link" href="<?= e(app_url('/login')) ?>">Back to sign in</a>
    </section>
</main>
</body>
</html>
