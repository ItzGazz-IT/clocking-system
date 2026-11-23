-- Time & Attendance
-- Reset all company/user/employee/device/attendance data while keeping schema and reference data.
--
-- Use this only when you are ready to start fresh.
-- Keeps:
--   permissions, system roles, role permissions, attendance event types,
--   windows event types, default attendance transitions, schema migrations.
-- Removes:
--   companies, users, employees, departments, shifts, devices, attendance,
--   Windows activity, alerts, reset requests, settings, audit logs, sessions/rate limits.

DROP PROCEDURE IF EXISTS reset_table_if_exists;

DELIMITER //
CREATE PROCEDURE reset_table_if_exists(IN p_table_name VARCHAR(64))
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
    ) THEN
        SET @reset_sql = CONCAT('TRUNCATE TABLE `', REPLACE(p_table_name, '`', '``'), '`');
        PREPARE reset_statement FROM @reset_sql;
        EXECUTE reset_statement;
        DEALLOCATE PREPARE reset_statement;
    END IF;
END//
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 0;

CALL reset_table_if_exists('network_access_log');
CALL reset_table_if_exists('anomaly_alerts');
CALL reset_table_if_exists('notifications');
CALL reset_table_if_exists('audit_logs');
CALL reset_table_if_exists('password_reset_requests');
CALL reset_table_if_exists('password_reset_tokens');
CALL reset_table_if_exists('login_attempts');
CALL reset_table_if_exists('api_rate_limits');
CALL reset_table_if_exists('leave_requests');
CALL reset_table_if_exists('public_holidays');
CALL reset_table_if_exists('overtime_rules');
CALL reset_table_if_exists('leave_types');
CALL reset_table_if_exists('breaks');
CALL reset_table_if_exists('attendance_events');
CALL reset_table_if_exists('attendance');
CALL reset_table_if_exists('windows_events');
CALL reset_table_if_exists('activity_usage_events');
CALL reset_table_if_exists('flexible_attendance_days');
CALL reset_table_if_exists('devices');
CALL reset_table_if_exists('employee_shifts');
CALL reset_table_if_exists('settings');
CALL reset_table_if_exists('user_roles');
CALL reset_table_if_exists('users');
CALL reset_table_if_exists('departments');
CALL reset_table_if_exists('shifts');
CALL reset_table_if_exists('employees');
CALL reset_table_if_exists('companies');

SET FOREIGN_KEY_CHECKS = 1;

DROP PROCEDURE IF EXISTS reset_table_if_exists;

-- Re-apply reference data to be safe.
INSERT INTO permissions (name, slug, description) VALUES
('View dashboard', 'dashboard.view', 'View attendance dashboard metrics'),
('Manage employees', 'employees.manage', 'Create and maintain employee records'),
('Record attendance', 'attendance.record', 'Create attendance events'),
('View own attendance', 'attendance.view.own', 'View the user employee attendance'),
('View department attendance', 'attendance.view.department', 'View attendance in managed departments'),
('View all attendance', 'attendance.view.all', 'View attendance across the company'),
('Manage settings', 'settings.manage', 'Change company rules and settings'),
('View reports', 'reports.view', 'View and export reports'),
('View audit log', 'audit.view', 'View immutable audit history'),
('Change own password', 'account.password.change', 'Change the current user password'),
('View departments', 'departments.view', 'View company departments'),
('Manage departments', 'departments.manage', 'Create and maintain departments'),
('View shifts', 'shifts.view', 'View company shifts'),
('Manage shifts', 'shifts.manage', 'Create and maintain shifts'),
('View employees', 'employees.view', 'View company employees'),
('Manage user accounts', 'users.manage', 'Provision, reset and disable user accounts'),
('Correct attendance', 'attendance.correct', 'Append audited attendance corrections'),
('View attendance history', 'attendance.history.view', 'View detailed attendance history')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO roles (company_id, name, slug, description, is_system)
SELECT NULL, 'Administrator', 'administrator', 'Full company administration role template', TRUE
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE company_id IS NULL AND slug = 'administrator');

INSERT INTO roles (company_id, name, slug, description, is_system)
SELECT NULL, 'Employee', 'employee', 'Employee self-service role template', TRUE
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE company_id IS NULL AND slug = 'employee');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug = 'administrator' AND r.is_system = TRUE;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'dashboard.view',
    'attendance.record',
    'attendance.view.own',
    'attendance.history.view',
    'account.password.change'
)
WHERE r.slug = 'employee' AND r.is_system = TRUE;

INSERT INTO attendance_event_types (code, name, category) VALUES
('clock_in', 'Clock In', 'work'),
('clock_out', 'Clock Out', 'work'),
('lunch_start', 'Start Lunch', 'lunch'),
('lunch_end', 'End Lunch', 'lunch'),
('break_start', 'Start Break', 'break'),
('break_end', 'End Break', 'break'),
('correction', 'Correction', 'administrative')
ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category);

INSERT INTO windows_event_types (code, name) VALUES
('login', 'Windows Login'),
('logout', 'Windows Logout'),
('lock', 'Workstation Lock'),
('unlock', 'Workstation Unlock'),
('sleep', 'System Sleep'),
('wake', 'System Wake'),
('shutdown', 'System Shutdown'),
('restart', 'System Restart'),
('idle', 'Idle Time Sample'),
('activity', 'Keyboard or Mouse Activity'),
('agent_exit', 'Agent Exited'),
('agent_uninstall', 'Agent Uninstall Started')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO attendance_event_transitions (company_id, from_event_type_id, to_event_type_id)
SELECT NULL, NULL, destination.id
FROM attendance_event_types destination
WHERE destination.code = 'clock_in'
  AND NOT EXISTS (
      SELECT 1 FROM attendance_event_transitions existing
      WHERE existing.company_id IS NULL
        AND existing.from_event_type_id IS NULL
        AND existing.to_event_type_id = destination.id
  );

INSERT INTO attendance_event_transitions (company_id, from_event_type_id, to_event_type_id)
SELECT NULL, source.id, destination.id
FROM attendance_event_types source
CROSS JOIN attendance_event_types destination
WHERE (
      (source.code = 'clock_in' AND destination.code IN ('break_start', 'lunch_start', 'clock_out'))
   OR (source.code = 'break_start' AND destination.code = 'break_end')
   OR (source.code = 'break_end' AND destination.code IN ('break_start', 'lunch_start', 'clock_out'))
   OR (source.code = 'lunch_start' AND destination.code = 'lunch_end')
   OR (source.code = 'lunch_end' AND destination.code IN ('break_start', 'lunch_start', 'clock_out'))
  )
  AND NOT EXISTS (
      SELECT 1 FROM attendance_event_transitions existing
      WHERE existing.company_id IS NULL
        AND existing.from_event_type_id = source.id
        AND existing.to_event_type_id = destination.id
  );
