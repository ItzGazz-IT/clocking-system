<button class="mobile-menu" type="button" aria-label="Open navigation" aria-controls="app-sidebar" aria-expanded="false">
    <span></span><span></span><span></span>
</button>
<?php
$businessName = '';
$workingHours = 'Flexible · automatic attendance';
if ($user?->companyId) {
    try {
        $businessStatement = \App\Core\Database::connection()->prepare(
            'SELECT name FROM companies WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $businessStatement->execute(['id' => $user->companyId]);
        $businessName = (string) ($businessStatement->fetchColumn() ?: '');
    } catch (\Throwable) {
        $businessName = '';
    }
}
?>
<aside class="sidebar" id="app-sidebar">
    <a class="brand" href="<?= e(app_url('/dashboard')) ?>">
        <strong><?= e(config('app.name')) ?></strong>
    </a>

    <nav aria-label="Main navigation">
        <p class="nav-label">Main</p>
        <a class="<?= active_path('/dashboard') ?>" href="<?= e(app_url('/dashboard')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z"/></svg>
            <span>Dashboard</span>
        </a>
        <?php if ($user?->can('attendance.view.all')): ?>
            <a class="<?= active_path('/reports/activity') ?>" href="<?= e(app_url('/reports/activity')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v4H4V4Zm0 6h7v10H4V10Zm9 0h7v4h-7v-4Zm0 6h7v4h-7v-4Z"/></svg>
                <span>Productivity</span>
            </a>
            <a class="<?= active_path('/alerts') ?>" href="<?= e(app_url('/alerts')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/></svg>
                <span>Review &amp; evidence</span>
            </a>
        <?php elseif ($user?->employeeId): ?>
            <a class="<?= active_path('/my/productivity') ?>" href="<?= e(app_url('/my/productivity')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v4H4V4Zm0 6h7v10H4V10Zm9 0h7v4h-7v-4Zm0 6h7v4h-7v-4Z"/></svg>
                <span>My productivity</span>
            </a>
        <?php endif; ?>
        <?php if ($user?->can('attendance.history.view') && $user?->employeeId): ?>
            <a class="<?= active_path('/attendance/history') ?>" href="<?= e(app_url('/attendance/history')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3a9 9 0 1 0 8.95 10H19.9A7 7 0 1 1 13 5v3l4-4-4-4v3Zm-1 4h2v6h-5v-2h3V7Z"/></svg>
                <span>My attendance</span>
            </a>
        <?php endif; ?>
        <?php if ($user?->can('attendance.view.all')): ?>
            <p class="nav-label nav-label-secondary">Reports</p>
            <a class="<?= active_path('/reports/weekly') ?>" href="<?= e(app_url('/reports/weekly')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18H5V3Zm3 4v2h8V7H8Zm0 4v2h8v-2H8Zm0 4v2h5v-2H8Z"/></svg>
                <span>Weekly report</span>
            </a>
            <a class="<?= active_path('/attendance/history') ?>" href="<?= e(app_url('/attendance/history')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3a9 9 0 1 0 8.95 10H19.9A7 7 0 1 1 13 5v3l4-4-4-4v3Zm-1 4h2v6h-5v-2h3V7Z"/></svg>
                <span>Employee records</span>
            </a>
            <?php if ($user?->can('attendance.correct')): ?>
                <a class="<?= active_path('/overtime-approvals') ?>" href="<?= e(app_url('/overtime-approvals')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 11h-5v-2h3V6h2Zm3.6 4.2-1.4-1.4 1.4-1.4 1.4 1.4Z"/></svg>
                    <span>Overtime approvals</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($user?->can('employees.view') || $user?->can('departments.view') || $user?->can('shifts.view')): ?>
            <p class="nav-label nav-label-secondary">Workforce</p>
            <?php if ($user?->can('employees.view')): ?>
                <a class="<?= active_path('/employees') ?>" href="<?= e(app_url('/employees')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3ZM8 11c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z"/></svg>
                    <span>Employees</span>
                </a>
            <?php endif; ?>
            <?php if ($user?->can('departments.view')): ?>
                <a class="<?= active_path('/departments') ?>" href="<?= e(app_url('/departments')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7V3H2v18h20V7H12ZM6 19H4v-2h2v2Zm0-4H4v-2h2v2Zm0-4H4V9h2v2Zm0-4H4V5h2v2Zm4 12H8v-2h2v2Zm0-4H8v-2h2v2Zm0-4H8V9h2v2Zm0-4H8V5h2v2Zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10Zm-2-8h-2v2h2v-2Zm0 4h-2v2h2v-2Z"/></svg>
                    <span>Departments</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($user?->can('settings.manage')): ?>
            <p class="nav-label nav-label-secondary">System</p>
            <?php if ($user?->can('attendance.view.all')): ?>
                <a class="<?= active_path('/devices') ?>" href="<?= e(app_url('/devices')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v10H4V5Zm2 2v6h12V7H6Zm2 10h8v2h3v2H5v-2h3v-2Zm10.5-5.5L16 9v1.7h-3v1.6h3V14l2.5-2.5Z"/></svg>
                    <span>Devices</span>
                </a>
            <?php endif; ?>
            <?php if ($user?->can('users.manage')): ?>
                <a class="<?= active_path('/users') ?>" href="<?= e(app_url('/users')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4Zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Zm7-3V8h-2V6h2V3h2v3h2v2h-2v3h-2Z"/></svg>
                    <span>User accounts</span>
                </a>
            <?php endif; ?>
            <a class="<?= active_path('/settings') ?>" href="<?= e(app_url('/settings')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7.08 7.08 0 0 0-1.62-.94L14.4 2.8a.48.48 0 0 0-.48-.4h-3.84a.47.47 0 0 0-.47.4l-.36 2.55c-.59.24-1.13.56-1.62.94l-2.39-.96a.47.47 0 0 0-.59.22L2.74 8.87a.46.46 0 0 0 .12.61l2.03 1.58c-.05.3-.09.62-.09.94s.03.64.08.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.31.59.22l2.39-.96c.5.38 1.04.7 1.62.94l.36 2.55c.04.24.24.4.48.4h3.84c.24 0 .44-.16.47-.4l.36-2.55c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.46.46 0 0 0-.12-.61l-2.02-1.58ZM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5Z"/></svg>
                <span>Settings</span>
            </a>
        <?php endif; ?>
        <p class="nav-label nav-label-secondary">Account</p>
        <a class="<?= active_path('/account') ?>" href="<?= e(app_url('/account/password')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8h-1V6a5 5 0 0 0-10 0v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2Zm-6 9a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm3.1-9H8.9V6a3.1 3.1 0 0 1 6.2 0v2Z"/></svg>
            <span>Security</span>
        </a>
        <a class="<?= active_path('/windows-app') ?>" href="<?= e(app_url('/windows-app/download')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20h14v-2H5v2ZM19 9h-4V3H9v6H5l7 7 7-7Z"/></svg>
            <span>Windows app</span>
        </a>
    </nav>


    <div class="sidebar-user">
        <span class="avatar"><?= e(initials($businessName ?: $user?->name)) ?></span>
        <span class="user-copy"><strong><?= e($businessName ?: config('app.name')) ?></strong><small><?= e($workingHours) ?></small></span>
        <form method="post" action="<?= e(app_url('/logout')) ?>">
            <?= csrf_field() ?>
            <button class="signout-button" type="submit" title="Sign out" aria-label="Sign out">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17v2H5V5h5v2h2V3H3v18h9v-4h-2Zm9-5-4-4v3H8v2h7v3l4-4Z"/></svg>
            </button>
        </form>
    </div>
</aside>
<div class="sidebar-overlay"></div>
<script defer src="<?= e(app_url('/assets/js/app.js')) ?>"></script>
