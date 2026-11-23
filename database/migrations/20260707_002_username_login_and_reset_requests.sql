-- Username-based login and no-email forgot-password requests.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS username VARCHAR(80) NULL AFTER employee_id;

UPDATE users u
LEFT JOIN employees e ON e.id = u.employee_id
SET u.username = LOWER(
    REGEXP_REPLACE(
        COALESCE(NULLIF(e.employee_number, ''), SUBSTRING_INDEX(u.email, '@', 1), CONCAT('user', u.id)),
        '[^A-Za-z0-9_.-]',
        ''
    )
)
WHERE u.username IS NULL OR u.username = '';

ALTER TABLE users
    ADD UNIQUE KEY IF NOT EXISTS uq_users_username (username);

CREATE TABLE IF NOT EXISTS password_reset_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    username VARCHAR(80) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    CONSTRAINT fk_password_reset_requests_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_password_reset_requests_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_password_reset_requests_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id),
    INDEX idx_password_reset_requests_status (status, requested_at),
    INDEX idx_password_reset_requests_user (user_id, status)
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('20260707_002_username_login_and_reset_requests');
