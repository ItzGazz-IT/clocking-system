ALTER TABLE attendance
    ADD COLUMN session_type ENUM('regular', 'overtime') NOT NULL DEFAULT 'regular' AFTER status,
    ADD INDEX idx_attendance_session_type (employee_id, work_date, session_type);
