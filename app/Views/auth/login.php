<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= e(app_url('/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(config('app.url')) ?>/assets/css/app.css?v=unbranded-1">
</head>
<body>
<main class="auth-shell">
    <section class="auth-card" aria-labelledby="login-title">
        <h1 id="login-title">Sign in</h1>
        <p class="muted">Time and attendance</p>
        <?php if (!empty($flash)): ?>
            <div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php if (!empty($setupOpen)): ?>
            <div class="setup-prompt">
                <strong>New installation?</strong>
                <span>Register your company and create the first admin account.</span>
                <a href="<?= e(app_url('/setup')) ?>">Register a new company</a>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors['credentials'])): ?>
            <div class="alert" role="alert"><?= e($errors['credentials']) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(config('app.url')) ?>/login">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <input id="username" name="username" autocomplete="username" maxlength="80"
                   value="<?= e(old('username')) ?>" required>
            <?php if (!empty($errors['username'])): ?><small class="error"><?= e($errors['username']) ?></small><?php endif; ?>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <?php if (!empty($errors['password'])): ?><small class="error"><?= e($errors['password']) ?></small><?php endif; ?>
            <button type="submit">Sign in</button>
        </form>
        <a class="auth-link" href="<?= e(app_url('/forgot-password')) ?>">Forgot password?</a>
    </section>
</main>
</body>
</html>
