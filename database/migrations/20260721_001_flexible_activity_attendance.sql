ALTER TABLE employees
    ADD COLUMN attendance_mode ENUM('fixed', 'flexible') NOT NULL DEFAULT 'fixed' AFTER employment_type,
    ADD COLUMN activity_attendance_effective_from DATE NULL AFTER attendance_mode,
    ADD COLUMN daily_target_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 480 AFTER activity_attendance_effective_from,
    ADD COLUMN idle_threshold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER daily_target_minutes,
    ADD COLUMN offline_timeout_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15 AFTER idle_threshold_minutes;

CREATE TABLE flexible_attendance_days (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    inactive_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    target_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 480,
    overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    overtime_status ENUM('none', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'none',
    overtime_reviewed_by_user_id BIGINT UNSIGNED NULL,
    overtime_reviewed_at DATETIME NULL,
    overtime_review_notes VARCHAR(500) NULL,
    last_activity_at DATETIME(6) NULL,
    last_calculated_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_flexible_day_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_flexible_day_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_flexible_day_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id),
    CONSTRAINT fk_flexible_day_reviewer FOREIGN KEY (overtime_reviewed_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_flexible_day_employee_date (employee_id, work_date),
    UNIQUE KEY uq_flexible_day_attendance (attendance_id),
    INDEX idx_flexible_day_overtime (company_id, overtime_status, work_date)
) ENGINE=InnoDB;

INSERT IGNORE INTO windows_event_types (code, name) VALUES ('activity', 'Keyboard or Mouse Activity');
INSERT IGNORE INTO schema_migrations (migration) VALUES ('20260721_001_flexible_activity_attendance');
