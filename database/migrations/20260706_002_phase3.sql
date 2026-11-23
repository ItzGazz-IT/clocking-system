-- Phase 3: employee user lifecycle and secure password recovery.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE AFTER password_changed_at;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_password_resets_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_password_reset_hash (token_hash),
    INDEX idx_password_resets_user_active (user_id, used_at, expires_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (name, slug, description) VALUES
('Manage user accounts', 'users.manage', 'Provision, reset and disable user accounts'),
('Correct attendance', 'attendance.correct', 'Append audited attendance corrections'),
('View attendance history', 'attendance.history.view', 'View detailed attendance history');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'administrator' AND r.is_system = 1;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'attendance.history.view'
WHERE r.slug IN ('manager', 'employee') AND r.is_system = 1;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('20260706_002_phase3');

