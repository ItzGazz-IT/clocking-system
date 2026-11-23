-- Production hardening: session revocation, API throttling, and split shifts.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER must_change_password;

CREATE TABLE IF NOT EXISTS api_rate_limits (
    rate_key VARCHAR(190) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_api_rate_limits_expiry (expires_at)
) ENGINE=InnoDB;

-- The current base schema already includes attendance.session_number and the
-- uq_attendance_employee_date_session key. Older installations performed that
-- conversion before deploying the application.

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('20260706_004_production_hardening');
