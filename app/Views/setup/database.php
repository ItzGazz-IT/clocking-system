<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database setup | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css')) ?>">
</head>
<body>
<main class="auth-shell setup-shell">
    <section class="auth-card setup-card" aria-labelledby="setup-title">
        <h1 id="setup-title">Database setup</h1>
        <?php if ($error): ?>
            <div class="alert" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if (!$enabled): ?>
            <p>Set the installer access key on the server to begin setup.</p>
        <?php elseif (!$authorized): ?>
            <form method="post" action="<?= e(app_url('/setup/database')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="authorize">
                <label for="setup_token">Installer access key</label>
                <input id="setup_token" name="setup_token" type="password" autocomplete="off" required>
                <button type="submit">Continue</button>
            </form>
        <?php elseif (!$connected): ?>
            <p>Enter credentials for an empty MySQL 8 database created through your hosting control panel.</p>
            <form method="post" action="<?= e(app_url('/setup/database')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="connect">
                <label for="host">Database host</label>
                <input id="host" name="host" value="127.0.0.1" required>
                <label for="port">Port</label>
                <input id="port" name="port" type="number" min="1" max="65535" value="3306" required>
                <label for="database">Database name</label>
                <input id="database" name="database" required>
                <label for="username">Database username</label>
                <input id="username" name="username" required>
                <label for="database_password">Database password</label>
                <input id="database_password" name="database_password" type="password" autocomplete="new-password">
                <button type="submit">Test and save connection</button>
            </form>
        <?php else: ?>
            <p>Database connected. Initialize it to create the application tables and required system records.</p>
            <form method="post" action="<?= e(app_url('/setup/database')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="initialize">
                <button type="submit">Initialize database</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
