# Autonomous Safeguards Implementation

Implemented five comprehensive autonomous safeguards to prevent user exploitation and ensure integrity of the attendance system:

## 1. Automated Idle Detection Validation ✓

**Location:** `AnomalyDetectionService::checkIdleTimeAnomalies()`

- Monitors Windows idle_seconds from devices
- Flags when employee is idle for >8 hours while appearing clocked in
- Automatically creates `idle_time_extended` alerts (severity: HIGH)
- Runs daily via maintenance script
- Alert includes: idle duration, device info, last clock event

**How it works:**
- Scans Windows events from last 7 days
- Correlates with attendance_events to find clock-in without clock-out
- Flags pattern: idle >28800 seconds + status='clocked_in'
- Creates audit trail for review

---

## 2. Device Integrity Checks ✓

**Location:** `WindowsEventService::validateWindowsEventIntegrity()` + `AttendanceService::validateDeviceIntegrity()`

### Timestamp Drift Validation
- **In real-time:** Rejects events where occurred_at is >1440 minutes old or >60 minutes in future
- **In batch:** `AnomalyDetectionService::checkTimestampIntegrity()` flags historical anomalies
- Creates `timestamp_drift` alerts (severity: HIGH)
- Prevents: time-traveling clock-ins, backdated events, device clock manipulation

### Simultaneous Device Detection
- **In real-time:** Rejects when same employee has events from multiple devices within 60 seconds
- **In batch:** Detects same-minute events from different devices
- Creates `simultaneous_devices` alerts (severity: HIGH)
- Prevents: clocking in from 2+ computers simultaneously

### Replay Attack Prevention
- Idempotency keys prevent identical requests from being processed twice
- All events include `idempotency_key` UUID requirement
- Windows events require explicit idempotency_key in payload

---

## 3. Schedule Enforcement ✓

**Location:** `AttendanceService::enforceScheduleConstraint()`

**What it does:**
- Validates clock_in/clock_out events against employee's assigned shift
- Checks: start_time, end_time, grace_period_minutes, spans_midnight flag
- Allows events within grace period (default: 30 min before/after shift)
- For out-of-hours events:
  - **Manual web/API:** Allows but creates audit warning
  - **Device events:** Allows but flagged by anomaly detection

**Prevents:**
- Clocking in before shift starts (outside grace period)
- Clocking out after shift ends (outside grace period)
- Weekend/off-day clock events

**Related alerts:**
- `schedule_violation` (severity: MEDIUM) - flagged by batch scan
- `after_hours_activity` (severity: LOW) - between 10 PM - 6 AM

---

## 4. Anomaly Detection in Maintenance Script ✓

**Location:** `bin/maintenance.php` + `AnomalyDetectionService`

**Runs daily** with the scheduled maintenance command:
```powershell
php -d extension=mbstring bin/maintenance.php
```

**Detects 6 types of anomalies:**

1. **Idle Time Extended** - Employee idle >8h while clocked in
2. **Timestamp Drift** - Event time differs from receive time by >1 day or <-1 hour
3. **Simultaneous Devices** - Same employee clocking from multiple devices
4. **Schedule Violations** - Clock events outside shift times
5. **Pattern Anomalies:**
   - Rapid clock cycles (<1 minute in/out)
   - After-hours activity (10 PM - 6 AM)
6. **Network Anomalies** - Unexpected IP/geographic location

**Alert Storage:**
- Stored in `anomaly_alerts` table
- Each alert includes:
  - Type, severity (high/medium/low), description
  - Related attendance/event/device/window_event IDs
  - Metadata (JSON) with specific details
  - Status: pending, dismissed, escalated
  - Review tracking: reviewer_id, reviewed_at, review_notes

**Deduplication:**
- Similar alerts within 1 hour are not duplicated
- Checks: employee_id + alert_type + status='pending' + created_at > 1 hour

---

## 5. Geofencing & Network Access Tracking ✓

**Location:** `AnomalyDetectionService::checkNetworkAnomolies()` + `network_access_log` table

**What it tracks:**
- Device IP address on each event
- Geographic location (country code if available)
- Expected vs unexpected access flags
- Event type (login, clock, sync, etc.)

**How admins use it:**
- Configure expected office IP ranges in settings (future implementation)
- System automatically flags access from unexpected networks
- Creates `unexpected_network` alerts (severity: MEDIUM)
- Useful for: detecting remote device hijacking, VPN bypass attempts

**Future enhancements:**
- IP whitelist per device
- VPN/proxy detection
- Geographic impossibility detection (too fast travel between locations)

---

## Admin Interface: Anomaly Alerts Dashboard

**Location:** `app/Controllers/AnomalyAlertsController.php` + Views in `app/Views/alerts/`

### Features:

**List View** (`/alerts`):
- Filter by: status (pending/dismissed/escalated), severity (high/medium/low), type
- Shows: employee, alert type, severity badge, description excerpt, status, created date
- Paginated results (25 per page)
- Color-coded severity: HIGH (red), MEDIUM (orange), LOW (yellow)

**Detail View** (`/alerts/{id}`):
- Full alert description and metadata
- Related event details (if applicable)
- Review history and notes
- For pending alerts: action form to dismiss or escalate
- For reviewed alerts: reviewer name and review date

### Review Actions:

1. **Dismiss** - Mark as resolved or false positive
   - Status: dismissed
   - Recorded: reviewer_id, reviewed_at, optional notes
   - Alert removed from pending view

2. **Escalate** - Flag for investigation
   - Status: escalated
   - Recorded: reviewer_id, reviewed_at, optional notes
   - Can be tracked for audit compliance

---

## Database Schema

### `anomaly_alerts` table:
```sql
- id (PK)
- company_id (FK)
- employee_id (FK)
- alert_type (idle_time_extended, timestamp_drift, etc.)
- severity (high/medium/low)
- description (text)
- related_attendance_id, related_event_id, related_device_id, related_window_event_id
- metadata (JSON)
- status (pending/dismissed/escalated)
- reviewed_by_user_id, reviewed_at, review_notes
- created_at, updated_at
```

### `network_access_log` table:
```sql
- id (PK)
- device_id (FK)
- employee_id (FK)
- ip_address
- country_code
- is_expected (boolean)
- event_type
- occurred_at
```

---

## Integration Points

### Attendance Recording:
- `AttendanceService::record()` calls `enforceScheduleConstraint()` and `validateDeviceIntegrity()`
- Blocks suspicious events with HttpException(422)
- Audit logged for investigation

### Windows Event Ingestion:
- `WindowsEventService::record()` calls `validateWindowsEventIntegrity()`
- Blocks timestamp drift >1440 min or <-60 min
- Blocks simultaneous devices
- Rejects if device time out of sync

### Daily Maintenance:
- Runs `AnomalyDetectionService::scanCompany()` for all companies
- Generates reports in console output
- Creates alerts for supervisory review

---

## Compliance & Audit

All safeguards are:
- ✓ Logged in audit_logs table with reason/metadata
- ✓ Timestamped (UTC)
- ✓ Attributed to source (API, web, device, system)
- ✓ Company-scoped (multi-tenant safe)
- ✓ Non-destructive (never delete source data, only flag alerts)

---

## Deployment Instructions

1. **Run database migration:**
   ```powershell
   php -d extension=mbstring bin/migrate.php
   ```

2. **Schedule daily maintenance:**
   Add to cron/Task Scheduler:
   ```powershell
   php -d extension=mbstring bin/maintenance.php
   ```
   Recommended: Run at 2 AM daily

3. **Configure optional settings:**
   - Set `attendance.overtime_after_minutes` in settings
   - Configure grace periods for shifts
   - Set expected IP ranges (future implementation)

4. **Monitor dashboard:**
   - Navigate to `/alerts` to review flagged anomalies
   - Filter by severity and status
   - Dismiss false positives or escalate for investigation

---

## Performance Considerations

- Anomaly scans run on 7-day rolling window
- Indexes on: status, created_at, alert_type, employee_id, severity
- Deduplication prevents alert explosion
- Batch operations use efficient GROUP BY / DISTINCT queries
- Real-time validation (device checks) minimal overhead

---

## Future Enhancements

1. **Machine Learning** - Adapt idle thresholds per employee role
2. **Geofencing** - IP whitelist, impossible travel detection
3. **Custom Rules** - Admins define company-specific patterns
4. **Notifications** - Email/SMS alerts for HIGH severity issues
5. **Integration** - Send to SIEM, compliance tools
