-- Make account security available to all normal users.

INSERT IGNORE INTO permissions (name, slug, description) VALUES
('Change own password', 'account.password.change', 'Change the current user password');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'account.password.change'
WHERE r.slug = 'employee' AND r.is_system = 1;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('20260707_001_account_security_access');
