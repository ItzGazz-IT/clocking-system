-- Anomaly Detection & Integrity Monitoring
-- Tracks flagged suspicious attendance patterns for supervisory review

CREATE TABLE anomaly_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    alert_type VARCHAR(80) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'medium',
    description VARCHAR(500) NOT NULL,
    related_attendance_id BIGINT UNSIGNED NULL,
    related_event_id BIGINT UNSIGNED NULL,
    related_device_id BIGINT UNSIGNED NULL,
    related_window_event_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    review_notes TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_anomaly_alerts_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_anomaly_alerts_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT fk_anomaly_alerts_attendance FOREIGN KEY (related_attendance_id) REFERENCES attendance(id),
    CONSTRAINT fk_anomaly_alerts_event FOREIGN KEY (related_event_id) REFERENCES attendance_events(id),
    CONSTRAINT fk_anomaly_alerts_device FOREIGN KEY (related_device_id) REFERENCES devices(id),
    CONSTRAINT fk_anomaly_alerts_window_event FOREIGN KEY (related_window_event_id) REFERENCES windows_events(id),
    CONSTRAINT fk_anomaly_alerts_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id),
    INDEX idx_anomaly_alerts_status (status, created_at),
    INDEX idx_anomaly_alerts_employee_status (employee_id, status, created_at),
    INDEX idx_anomaly_alerts_severity (severity, status),
    INDEX idx_anomaly_alerts_type (alert_type, created_at)
) ENGINE=InnoDB;

-- Track geofencing violations by IP/network if configured
CREATE TABLE network_access_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    country_code CHAR(2) NULL,
    is_expected BOOLEAN NOT NULL DEFAULT TRUE,
    event_type VARCHAR(40) NOT NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_network_access_device FOREIGN KEY (device_id) REFERENCES devices(id),
    CONSTRAINT fk_network_access_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    INDEX idx_network_access_device_time (device_id, occurred_at),
    INDEX idx_network_access_employee_time (employee_id, occurred_at),
    INDEX idx_network_access_expected (is_expected, occurred_at)
) ENGINE=InnoDB;
