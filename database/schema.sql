-- Time & Attendance - Phase 1
-- Target: MySQL 8.0+, InnoDB, utf8mb4

-- Import this file into the database selected by your deployment environment.
-- Database creation is intentionally excluded because hosted accounts commonly
-- do not have CREATE DATABASE permission.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    registration_number VARCHAR(80) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Johannesburg',
    email VARCHAR(254) NULL,
    phone VARCHAR(40) NULL,
    address JSON NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    INDEX idx_companies_active (is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_departments_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_departments_parent FOREIGN KEY (parent_id) REFERENCES departments(id),
    UNIQUE KEY uq_departments_company_code (company_id, code),
    INDEX idx_departments_company_active (company_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    spans_midnight BOOLEAN NOT NULL DEFAULT FALSE,
    paid_minutes SMALLINT UNSIGNED NULL,
    grace_period_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expected_lunch_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rules JSON NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_shifts_company FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY uq_shifts_company_code (company_id, code),
    INDEX idx_shifts_company_active (company_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE employees (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    manager_employee_id BIGINT UNSIGNED NULL,
    employee_number VARCHAR(50) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NULL,
    phone VARCHAR(40) NULL,
    hire_date DATE NULL,
    termination_date DATE NULL,
    employment_status VARCHAR(40) NOT NULL DEFAULT 'active',
    employment_type VARCHAR(40) NULL,
    attendance_mode ENUM('fixed', 'flexible') NOT NULL DEFAULT 'flexible',
    activity_attendance_effective_from DATE NULL,
    daily_target_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 480,
    idle_threshold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    offline_timeout_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    timezone VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_employees_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_employees_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_employees_manager FOREIGN KEY (manager_employee_id) REFERENCES employees(id),
    UNIQUE KEY uq_employees_company_number (company_id, employee_number),
    INDEX idx_employees_department_status (department_id, employment_status, deleted_at),
    INDEX idx_employees_manager (manager_employee_id)
) ENGINE=InnoDB;

ALTER TABLE departments
    ADD COLUMN manager_employee_id BIGINT UNSIGNED NULL AFTER parent_id,
    ADD CONSTRAINT fk_departments_manager FOREIGN KEY (manager_employee_id) REFERENCES employees(id);

CREATE TABLE employee_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    shift_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    days_of_week JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_employee_shifts_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_employee_shifts_shift FOREIGN KEY (shift_id) REFERENCES shifts(id),
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
    INDEX idx_employee_shifts_effective (employee_id, effective_from, effective_to)
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NULL,
    username VARCHAR(80) NULL,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    email_verified_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    password_changed_at TIMESTAMP NULL,
    must_change_password BOOLEAN NOT NULL DEFAULT FALSE,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_employee (employee_id),
    INDEX idx_users_company_active (company_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_roles_company FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY uq_roles_company_slug (company_id, slug),
    INDEX idx_roles_system_slug (is_system, slug)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
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

CREATE TABLE password_reset_requests (
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

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB;

CREATE TABLE attendance_event_types (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(40) NOT NULL,
    is_system BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_attendance_event_types_code (code)
) ENGINE=InnoDB;

CREATE TABLE attendance_event_transitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    from_event_type_id SMALLINT UNSIGNED NULL,
    to_event_type_id SMALLINT UNSIGNED NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_transitions_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_event_transitions_from FOREIGN KEY (from_event_type_id) REFERENCES attendance_event_types(id),
    CONSTRAINT fk_event_transitions_to FOREIGN KEY (to_event_type_id) REFERENCES attendance_event_types(id),
    UNIQUE KEY uq_event_transition (company_id, from_event_type_id, to_event_type_id),
    INDEX idx_event_transitions_lookup (company_id, from_event_type_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    shift_id BIGINT UNSIGNED NULL,
    work_date DATE NOT NULL,
    session_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(40) NOT NULL DEFAULT 'open',
    session_type ENUM('regular', 'overtime') NOT NULL DEFAULT 'regular',
    scheduled_start_at DATETIME(6) NULL,
    scheduled_end_at DATETIME(6) NULL,
    opened_at DATETIME(6) NULL,
    closed_at DATETIME(6) NULL,
    calculated_work_minutes INT UNSIGNED NULL,
    calculated_break_minutes INT UNSIGNED NULL,
    calculated_overtime_minutes INT UNSIGNED NULL,
    calculation_version VARCHAR(40) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_attendance_shift FOREIGN KEY (shift_id) REFERENCES shifts(id),
    UNIQUE KEY uq_attendance_employee_date_session (employee_id, work_date, session_number),
    INDEX idx_attendance_date_status (work_date, status, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    device_uuid CHAR(36) NOT NULL,
    device_type VARCHAR(40) NOT NULL DEFAULT 'windows_agent',
    api_key_identifier VARCHAR(64) NULL,
    api_key_hash VARCHAR(255) NULL,
    hostname VARCHAR(255) NULL,
    operating_system VARCHAR(160) NULL,
    agent_version VARCHAR(40) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_devices_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_devices_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    UNIQUE KEY uq_devices_uuid (device_uuid),
    UNIQUE KEY uq_devices_api_identifier (api_key_identifier),
    INDEX idx_devices_company_active (company_id, is_active, deleted_at)
) ENGINE=InnoDB;

CREATE TABLE attendance_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    event_type_id SMALLINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NULL,
    recorded_by_user_id BIGINT UNSIGNED NULL,
    occurred_at DATETIME(6) NOT NULL,
    received_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    source VARCHAR(40) NOT NULL,
    idempotency_key CHAR(36) NOT NULL,
    ip_address VARCHAR(45) NULL,
    computer_name VARCHAR(255) NULL,
    metadata JSON NULL,
    correction_of_event_id BIGINT UNSIGNED NULL,
    correction_reason VARCHAR(500) NULL,
    CONSTRAINT fk_attendance_events_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id),
    CONSTRAINT fk_attendance_events_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_attendance_events_type FOREIGN KEY (event_type_id) REFERENCES attendance_event_types(id),
    CONSTRAINT fk_attendance_events_device FOREIGN KEY (device_id) REFERENCES devices(id),
    CONSTRAINT fk_attendance_events_user FOREIGN KEY (recorded_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_attendance_events_correction FOREIGN KEY (correction_of_event_id) REFERENCES attendance_events(id),
    UNIQUE KEY uq_attendance_events_idempotency (idempotency_key),
    INDEX idx_attendance_events_employee_time (employee_id, occurred_at),
    INDEX idx_attendance_events_attendance_time (attendance_id, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE breaks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    break_type VARCHAR(40) NOT NULL,
    start_event_id BIGINT UNSIGNED NOT NULL,
    end_event_id BIGINT UNSIGNED NULL,
    calculated_minutes INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_breaks_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id),
    CONSTRAINT fk_breaks_start_event FOREIGN KEY (start_event_id) REFERENCES attendance_events(id),
    CONSTRAINT fk_breaks_end_event FOREIGN KEY (end_event_id) REFERENCES attendance_events(id),
    UNIQUE KEY uq_breaks_start_event (start_event_id),
    UNIQUE KEY uq_breaks_end_event (end_event_id)
) ENGINE=InnoDB;

CREATE TABLE windows_event_types (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_windows_event_types_code (code)
) ENGINE=InnoDB;

CREATE TABLE windows_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NULL,
    event_type_id SMALLINT UNSIGNED NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    received_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    idempotency_key CHAR(36) NOT NULL,
    idle_seconds INT UNSIGNED NULL,
    metadata JSON NULL,
    CONSTRAINT fk_windows_events_device FOREIGN KEY (device_id) REFERENCES devices(id),
    CONSTRAINT fk_windows_events_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_windows_events_type FOREIGN KEY (event_type_id) REFERENCES windows_event_types(id),
    UNIQUE KEY uq_windows_events_idempotency (idempotency_key),
    INDEX idx_windows_events_device_time (device_id, occurred_at),
    INDEX idx_windows_events_employee_time (employee_id, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE activity_usage_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    usage_type VARCHAR(20) NOT NULL DEFAULT 'app',
    app_name VARCHAR(120) NOT NULL,
    window_title VARCHAR(255) NULL,
    website_domain VARCHAR(190) NULL,
    started_at DATETIME(6) NOT NULL,
    ended_at DATETIME(6) NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    idempotency_key CHAR(36) NOT NULL,
    metadata JSON NULL,
    received_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_activity_usage_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_activity_usage_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_activity_usage_device FOREIGN KEY (device_id) REFERENCES devices(id),
    UNIQUE KEY uq_activity_usage_idempotency (idempotency_key),
    INDEX idx_activity_usage_company_time (company_id, started_at),
    INDEX idx_activity_usage_employee_time (employee_id, started_at),
    INDEX idx_activity_usage_app_time (company_id, app_name, started_at),
    INDEX idx_activity_usage_domain_time (company_id, website_domain, started_at)
) ENGINE=InnoDB;

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

CREATE TABLE productivity_classification_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    match_target ENUM('app', 'website') NOT NULL,
    pattern VARCHAR(190) NOT NULL,
    category ENUM('productive', 'neutral', 'distracting') NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_productivity_rules_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_productivity_rules_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_productivity_rule (company_id, match_target, pattern),
    INDEX idx_productivity_rule_lookup (company_id, match_target, category)
) ENGINE=InnoDB;

CREATE TABLE leave_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_paid BOOLEAN NOT NULL DEFAULT FALSE,
    rules JSON NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_leave_types_company FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY uq_leave_types_company_code (company_id, code)
) ENGINE=InnoDB;

CREATE TABLE leave_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id BIGINT UNSIGNED NOT NULL,
    leave_type_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    day_fraction DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    reason TEXT NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    review_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_leave_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id),
    CONSTRAINT fk_leave_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id),
    CHECK (end_date >= start_date),
    INDEX idx_leave_employee_dates (employee_id, start_date, end_date, status)
) ENGINE=InnoDB;

CREATE TABLE overtime_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    conditions JSON NOT NULL,
    calculation JSON NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_overtime_rules_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CHECK (effective_to IS NULL OR effective_to >= effective_from),
    INDEX idx_overtime_rules_effective (company_id, is_active, effective_from, effective_to)
) ENGINE=InnoDB;

CREATE TABLE public_holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    holiday_date DATE NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_paid BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_public_holidays_company FOREIGN KEY (company_id) REFERENCES companies(id),
    UNIQUE KEY uq_public_holidays_company_date (company_id, holiday_date)
) ENGINE=InnoDB;

CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    value JSON NOT NULL,
    value_type VARCHAR(30) NOT NULL,
    is_sensitive BOOLEAN NOT NULL DEFAULT FALSE,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id),
    UNIQUE KEY uq_settings_company_key (company_id, setting_key)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    employee_id BIGINT UNSIGNED NULL,
    type VARCHAR(80) NOT NULL,
    channel VARCHAR(40) NOT NULL,
    subject VARCHAR(255) NULL,
    payload JSON NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    CONSTRAINT fk_notifications_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_notifications_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    INDEX idx_notifications_delivery (status, scheduled_at),
    INDEX idx_notifications_user_read (user_id, read_at)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    computer_name VARCHAR(255) NULL,
    user_agent VARCHAR(500) NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    reason TEXT NULL,
    occurred_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_audit_logs_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_audit_logs_entity (entity_type, entity_id, occurred_at),
    INDEX idx_audit_logs_user_time (user_id, occurred_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_throttle (email, ip_address, succeeded, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE api_rate_limits (
    rate_key VARCHAR(190) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_api_rate_limits_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE schema_migrations (
    migration VARCHAR(190) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
