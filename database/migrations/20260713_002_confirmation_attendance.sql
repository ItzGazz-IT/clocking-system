INSERT IGNORE INTO settings (company_id, setting_key, value, value_type)
SELECT id, 'attendance.absence_after_minutes', '60', 'integer' FROM companies;

INSERT IGNORE INTO settings (company_id, setting_key, value, value_type)
SELECT id, 'attendance.missing_clock_out_after_minutes', '30', 'integer' FROM companies;
