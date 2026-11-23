-- Keep historical manual attendance intact; all new employees default to flexible.
ALTER TABLE employees MODIFY COLUMN attendance_mode ENUM('fixed', 'flexible') NOT NULL DEFAULT 'flexible';

-- Already enabled flexible employees retain their original effective date.
-- For legacy employees with manual attendance today, switch at the next local
-- midnight to avoid counting the same day twice. Named timezone data may be
-- unavailable on shared hosting; the default timezone is UTC+02:00.
UPDATE employees e JOIN companies c ON c.id = e.company_id
SET e.activity_attendance_effective_from = CASE
        WHEN e.attendance_mode = 'flexible' AND e.activity_attendance_effective_from IS NOT NULL
            THEN e.activity_attendance_effective_from
        ELSE DATE_ADD(
            DATE(COALESCE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', COALESCE(e.timezone,c.timezone)),
                          CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+02:00'))),
            INTERVAL (EXISTS (
                SELECT 1 FROM attendance a WHERE a.employee_id=e.id AND a.deleted_at IS NULL
                AND a.work_date=DATE(COALESCE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', COALESCE(e.timezone,c.timezone)),
                                             CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+02:00')))
                AND COALESCE(a.calculation_version, '') NOT LIKE 'flexible-%'
            )) DAY)
    END,
    e.attendance_mode = 'flexible'
WHERE e.deleted_at IS NULL AND (e.attendance_mode <> 'flexible' OR e.activity_attendance_effective_from IS NULL);
