-- Phase 2: configurable attendance workflow and default company rules.

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(190) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_event_transitions (
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

INSERT IGNORE INTO permissions (name, slug, description) VALUES
('Change own password', 'account.password.change', 'Change the current user password'),
('View departments', 'departments.view', 'View company departments'),
('Manage departments', 'departments.manage', 'Create and maintain departments'),
('View shifts', 'shifts.view', 'View company shifts'),
('Manage shifts', 'shifts.manage', 'Create and maintain shifts'),
('View employees', 'employees.view', 'View company employees');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug = 'administrator' AND r.is_system = 1;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'account.password.change', 'departments.view', 'shifts.view', 'employees.view'
)
WHERE r.slug = 'manager' AND r.is_system = 1;

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'account.password.change'
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

INSERT IGNORE INTO settings (company_id, setting_key, value, value_type)
SELECT id, 'attendance.default_grace_minutes', '5', 'integer' FROM companies;
INSERT IGNORE INTO settings (company_id, setting_key, value, value_type)
SELECT id, 'attendance.default_lunch_minutes', '60', 'integer' FROM companies;
INSERT IGNORE INTO settings (company_id, setting_key, value, value_type)
SELECT id, 'attendance.overtime_after_minutes', '480', 'integer' FROM companies;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('20260706_001_phase2');
