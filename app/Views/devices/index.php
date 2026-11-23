<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Devices | <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css?v=20260710-daily-attendance')) ?>">
    <link rel="icon" href="<?= e(app_url('/favicon.svg?v=clock-1')) ?>" type="image/svg+xml">
</head>
<body>
<?php require BASE_PATH . '/app/Views/partials/nav.php'; ?>

<main class="container">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Windows Agent</p>
            <h1>Devices</h1>
        </div>
        <p class="muted"><?= count($devices) ?> registered devices · Current agent <?= e(config('app.agent_version', '1.0.1')) ?></p>
    </div>

    <?php if ($flash): ?>
        <div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($newToken): ?>
        <section class="panel">
            <h2>Copy this device token now</h2>
            <p class="muted">This token will only be shown once.</p>
            <input readonly value="Bearer <?= e($newToken) ?>" onclick="this.select()">
        </section>
    <?php endif; ?>

    <div class="content-grid">
        <section class="panel">
            <h2>Register device</h2>

            <form method="post" action="<?= e(app_url('/devices')) ?>">
                <?= csrf_field() ?>

                <label for="name">Device name</label>
                <input id="name" name="name" placeholder="Gareth Laptop" required>

                <label for="employee_id">Assigned employee</label>
                <select id="employee_id" name="employee_id" required>
                    <option value="">Choose employee</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= e($employee['id']) ?>">
                            <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="hostname">Hostname</label>
                <input id="hostname" name="hostname" placeholder="OFFICE-PC01">

                <label for="operating_system">Operating system</label>
                <input id="operating_system" name="operating_system" value="Windows 11">

                <button type="submit">Register device</button>
            </form>
        </section>

        <section class="panel table-panel">
            <h2>Registered devices</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Device</th>
                        <th>Employee</th>
                        <th>Hostname</th>
                        <th>Status</th>
                        <th>Last seen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td>
                                <strong><?= e($device['name']) ?></strong>
                                <small><?= e($device['device_type']) ?> · Agent <?= e($device['agent_version'] ?? 'unknown') ?></small>
                            </td>
                            <td><?= e($device['employee_name'] ?? '—') ?></td>
                            <td><?= e($device['hostname'] ?? '—') ?></td>
                            <td>
                                <span class="badge">
                                    <?= (int) $device['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= e($device['last_seen_at'] ? local_datetime($device['last_seen_at']) : 'Never') ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($devices === []): ?>
                        <tr>
                            <td colspan="5" class="empty">No devices registered yet.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
</body>
</html>
