<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($status) ?> | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(config('app.url')) ?>/assets/css/app.css?v=20260710-daily-attendance">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<main class="auth-shell">
    <section class="auth-card">
        <p class="eyebrow">Error <?= e($status) ?></p>
        <h1><?= e($message) ?></h1>
        <a href="<?= e(config('app.url')) ?>">Return home</a>
    </section>
</main>
</body>
</html>

