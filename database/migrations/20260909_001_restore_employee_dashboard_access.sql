-- Restore the baseline Employee self-service permissions from database/seed.sql.
-- Does not change passwords, user role assignments, or administrator permissions.
-- Safe to run again: both tables have unique keys for these entries.
INSERT IGNORE INTO permissions (name, slug, description)
VALUES ('View dashboard', 'dashboard.view', 'View attendance dashboard metrics'),
       ('Record attendance', 'attendance.record', 'Record attendance events'),
       ('View own attendance', 'attendance.view.own', 'View the user employee attendance'),
       ('View attendance history', 'attendance.history.view', 'View attendance history within the employee access scope');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('dashboard.view', 'attendance.record', 'attendance.view.own', 'attendance.history.view')
WHERE r.slug = 'employee' AND r.is_system = 1 AND r.deleted_at IS NULL;
