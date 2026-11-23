-- Import into the same selected database as schema.sql.

INSERT INTO permissions (name, slug, description) VALUES
('View dashboard', 'dashboard.view', 'View attendance dashboard metrics'),
('Manage employees', 'employees.manage', 'Create and maintain employee records'),
('Record attendance', 'attendance.record', 'Create attendance events'),
('View own attendance', 'attendance.view.own', 'View the user employee attendance'),
('View department attendance', 'attendance.view.department', 'View attendance in managed departments'),
('View all attendance', 'attendance.view.all', 'View attendance across the company'),
('Manage settings', 'settings.manage', 'Change company rules and settings'),
('View reports', 'reports.view', 'View and export reports'),
('View audit log', 'audit.view', 'View immutable audit history')
,
('Change own password', 'account.password.change', 'Change the current user password'),
('View departments', 'departments.view', 'View company departments'),
('Manage departments', 'departments.manage', 'Create and maintain departments'),
('View shifts', 'shifts.view', 'View company shifts'),
('Manage shifts', 'shifts.manage', 'Create and maintain shifts'),
('View employees', 'employees.view', 'View company employees')
,
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
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'administrator';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug IN ('dashboard.view', 'attendance.record', 'attendance.view.own', 'account.password.change')
WHERE r.slug = 'employee';

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

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'attendance.history.view'
WHERE r.slug = 'employee' AND r.is_system = 1;

INSERT INTO attendance_event_transitions (company_id, from_event_type_id, to_event_type_id)
SELECT NULL, NULL, destination.id
FROM attendance_event_types destination
WHERE destination.code = 'clock_in'
  AND NOT EXISTS (
      SELECT 1 FROM attendance_event_transitions existing
      WHERE existing.company_id IS NULL AND existing.from_event_type_id IS NULL
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
      WHERE existing.company_id IS NULL AND existing.from_event_type_id = source.id
        AND existing.to_event_type_id = destination.id
  );
