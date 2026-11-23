# Time & Attendance Tracking

Time & Attendance Tracking is a web-based employee clocking system with a Windows tray app. It is built for a single company installation where staff can clock in/out from the website or from the Windows tray app, and admins can review attendance, devices, reports, and Windows activity evidence.

The app is designed for South African usage by default. Company and employee times should be stored and displayed using `Africa/Johannesburg`.

## What The System Does

- Registers a company and creates the first administrator from the login page.
- Lets admins create departments, shifts, employees, and user accounts.
- Lets employees clock in, clock out, start/end lunch, and start/end breaks.
- Allows employees to clock in before their shift starts.
- Calculates working time from the scheduled shift start time, not from an early clock-in. For example, if the shift starts at `08:00` and the employee clocks in at `07:35`, counted work time starts at `08:00`.
- Tracks multiple sessions per day if an employee clocks out and later clocks in again.
- Records attendance events permanently for audit history.
- Provides daily and weekly attendance reports with CSV/PDF exports.
- Provides a Windows tray app for clocking and device activity.
- Records Windows activity such as idle, lock, unlock, sleep, wake, logout, and shutdown events when Windows exposes those events to the signed-in tray process.
- Shows linked Windows devices in the admin Devices screen.
- Shows Windows Activity per user/device for admin review.
- Shows Anomaly Alerts for suspicious or unusual attendance/device patterns.
- Lets users change their own password from the Security page if they have permission.
- Lets admins adjust the company theme colours from settings.

## Main Screens

### Dashboard

The dashboard shows live attendance counts, quick clocking actions, the Windows app download, version numbers, and a getting-started checklist for admins.

Employees see their own clocking panel. Admins can see broader company metrics and reports.

### Attendance History

Attendance History shows clock-in/out records, breaks, worked time, and overtime. Admin users can append or correct missed events. Corrections are audited.

### Reports

Reports show weekly attendance and allow exports to CSV/PDF. Worked time is displayed in hours and minutes.

### Devices

Devices shows linked Windows PCs, which employee they belong to, the hostname, last seen time, and agent version. A device must be linked to an employee before Windows activity and tray clocking can be accepted.

### Review & Evidence

The Review & Evidence workspace combines anomaly alerts with raw supporting evidence from the tray app. Administrators can filter both lists by employee, review an alert, inspect its detail, and dismiss or escalate it. The evidence log can show:

- Idle samples
- Latest idle duration
- Lock and unlock events
- Sleep and wake events
- Login/logout/session events when available
- Shutdown events when available
- Device and hostname
- Employee linked to the device

Alerts and evidence do not replace attendance records. Alerts are warnings created by the anomaly scanner, while the evidence log helps administrators understand what happened around clock-in/out times.

### Productivity Insights

The Productivity page combines foreground application and website activity with attendance context. It includes:

- Configurable productive, neutral, and distracting classification rules
- A focus score based only on classified productive and distracting time
- Classification confidence and unclassified-time visibility
- Tracking coverage compared with calculated clocked time
- Automated insights and an hourly classified-activity timeline
- Date-range, employee, department, classification, source, and minimum-duration filters
- Employee drill-down while retaining the current filters
- A dedicated employee activity report with day-by-day attendance, estimated idle episodes, applications, websites, and significant attendance/Windows/alert events
- Per-employee CSV export containing the daily summary, one row per significant event, and daily application and website breakdowns

Tracking coverage and activity classifications are supporting indicators, not proof of employee output. Meetings, calls, offline work, and role-specific tools should be considered when reviewing the dashboard.

Windows idle events are cumulative samples. The employee report groups consecutive samples into idle episodes and uses each episode's maximum duration; it does not add every raw sample, which would overstate idle time.

## When Anomaly Alerts Show

Anomaly alerts are created when the maintenance scanner runs:

```powershell
php bin/maintenance.php
```

On production hosting, this should be scheduled as a cron job, for example every 15 to 60 minutes. If the scanner is not scheduled, the evidence log can still receive events, but the alert queue may not populate automatically.

The scanner checks recent activity, generally the last 7 days, and avoids creating duplicate pending alerts of the same type for the same employee within 12 hours.

## Flexible activity-based attendance

Employees can be configured as either `Fixed shift · manual clocking` or `Flexible · automatic activity tracking`, with an effective-from date so historical attendance is unchanged.

For flexible employees the Windows Agent sends activity heartbeats and Windows session events. Login, unlock, wake, keyboard activity and mouse activity start or resume work. Lock, sleep, logout and shutdown stop work immediately. When unlocked inactivity reaches the employee's threshold (30 minutes by default), the complete idle period is excluded. If the agent stops reporting, work stops at the last confirmed activity once the offline timeout (15 minutes by default) expires.

Activity is split at local midnight. Regular worked time is capped at the employee's daily target (480 minutes by default). Excess active minutes enter the Overtime approvals queue; only approved overtime is included in reports. Weekend and non-working-day activity is recorded without creating an expected-hours shortfall.

### Idle Time Extended

Shows when an employee is still clocked in and the Windows agent reports a long idle period.

- Medium severity: idle for 2 hours or more.
- High severity: idle for 4 hours or more.

Example: an employee clocks in, walks away, and the PC reports 4.5 hours idle while the last attendance event is still `clock_in`.

### Timestamp Drift

Shows when a Windows-agent attendance event has a suspicious time difference between when the device says it happened and when the server received it.

- High severity when the event appears more than 24 hours old.
- High severity when the event appears more than 60 minutes in the future.

This helps catch device clock problems or manipulated event times.

### Simultaneous Devices

Shows when the same employee has Windows-agent clock events from more than one device in the same minute.

Example: the same employee appears to clock from a laptop and a desktop at nearly the same time.

### Schedule Violation

Shows when a clock-in or clock-out is outside the assigned shift window, with a grace allowance.

Important: employees are allowed to clock in early. The system still calculates worked time from the scheduled start time. A schedule alert is just an admin review item, not a block.

### Rapid Clock Cycle

Shows when an employee clocks in and clocks out again in less than 60 seconds.

Example: accidental double-clicking or testing the button during a live workday.

### Unexpected Network

Shows when network access logging is configured and a device appears from an unexpected IP/location.

This depends on `network_access_log` data being populated. If that table is not being fed by your environment, this alert type will not normally appear.

## Windows Tray App

The Windows tray app is the employee PC agent. It installs per machine under `Program Files` and requires administrator rights to install or uninstall.

The tray app can:

- Link the PC to the logged-in employee.
- Clock in/out.
- Start/end lunch.
- Start/end breaks.
- Send Windows activity automatically.
- Show foreground confirmations before shift start and shift end.
- Start automatically when Windows starts.

The installer is:

```text
public/downloads/Time-Attendance-Agent-Setup.exe
```

In the web app, logged-in users can download it from the Windows app/dashboard download button.

The current expected installer version is `1.1.4`.

## Windows Agent Linking Flow

1. Employee logs into the web app.
2. Employee downloads the Windows app.
3. An administrator runs the installer on the employee PC.
4. The tray app starts automatically.
5. Employee opens tray Settings.
6. Employee enters the server URL, username, and password.
7. Employee clicks `Link this device`.
8. The server creates/updates the device record and returns the token automatically.
9. After linking, `Test connection` becomes available.
10. Admin can confirm the device under Devices.

Normal users do not need to see or copy device tokens.

## Shift Confirmations

The Windows agent checks the employee's assigned shift in the background and opens a foreground confirmation window, even when the settings window is closed.

- Clock-in confirmation opens 5 minutes before shift start and remains available until confirmed.
- A confirmation through the shift grace period is on time. A later confirmation is recorded as late using server time.
- If no confirmation arrives by the configured absence threshold, the dashboard shows the employee as absent.
- Clock-out confirmation opens 5 minutes before shift end. If the employee is still on a break or lunch, confirming clock-out ends it first.
- If clock-out is not confirmed, maintenance waits for the configured threshold and then records a system clock-out at the scheduled shift end. The generated event is labelled `scheduled_auto_clock_out` for audit and reporting.
- Closing the window only snoozes it briefly. It remains a normal taskbar window rather than a tray balloon.

Because the agent starts with Windows, confirmations work after the employee signs into Windows and the tray process is running. Attendance is never created merely because the agent is online.

## Early Clock-In Rule

Employees can clock in at any time. This helps with real workplaces where staff arrive early and want to confirm they are present.

Worked time is still calculated from the scheduled shift start time. For an `08:00` shift:

- Clock in at `07:30`
- Clock out at `17:00`
- Counted work window starts at `08:00`

The actual early clock-in remains visible as an attendance event for audit purposes.

## Production Setup

1. Host the app with its document root set to `public/`. Install Composer dependencies and enable the PHP extensions listed in `composer.json`, including PDO MySQL and mbstring.
2. Create a dedicated, empty MySQL 8 database and a user with the privileges needed to create tables and read and write application records.
3. Copy `.env.production.example` to `.env`. Set the HTTPS `APP_URL`, generate a random `APP_KEY` of at least 64 characters, and set `SETUP_TOKEN` to a random secret of at least 32 characters. Leave `DB_DATABASE` empty for browser setup. Configure `DB_SSL_CA` and `DB_SSL_VERIFY=true` when the database connection requires TLS.
4. Create `logs/` and `uploads/` and grant the web process write access to those directories. Grant it temporary write access to `config/` to save the database connection.
5. Open `/setup/database`, enter the installer access key, and choose **Test and save connection**. Then choose **Initialize database**.
6. Enter your company details and create the first administrator. Sign in at `/login`. Remove `SETUP_TOKEN` from the server configuration and revoke write access to `config/` after setup. Keep the saved database configuration readable by the application.
7. Schedule `php bin/maintenance.php` to run regularly.

The installer saves credentials in `config/database.local.json` outside the public document root. If initialization fails after tables have started to appear, correct the database permissions and retry from the same page. Existing installations that already set `DB_DATABASE` continue to use their environment configuration and the CLI migration command.


## Production Maintenance Job

Run this regularly:

```powershell
php bin/maintenance.php
```

It removes old temporary records and runs anomaly detection for active companies.

Recommended schedule:

- Pilot: every 30 to 60 minutes.
- Live workplace: every 15 to 30 minutes.

## Local Development

Run `composer install`, then copy `.env.production.example` to `.env`. Set `APP_ENV=local`, `APP_URL=http://127.0.0.1:8080`, and `SESSION_SECURE=false`. Set a random `SETUP_TOKEN` and follow the browser setup steps with a dedicated empty MySQL database.

```powershell
php -S 127.0.0.1:8080 -t public server.php
php tests/run.php
```


## Building The Windows Installer

From the project root:

```powershell
powershell -ExecutionPolicy Bypass -File installer/build-agent-installer.ps1 -Version 1.2.1
```

The installer is created at:

```text
dist/Time-Attendance-Agent-Setup.exe
```

After rebuilding, copy it into:

```text
public/downloads/Time-Attendance-Agent-Setup.exe
downloads/Time-Attendance-Agent-Setup.exe
```

Then rebuild the web production zip.

## Agent Auto Updates

The Windows agent checks the server endpoint below at startup and then every 6 hours:

```text
/api/agent-update
```

The endpoint uses the linked device token and returns the configured `AGENT_VERSION`
plus the installer URL. To publish an update:

1. Build a new installer with a higher version.
2. Copy it to `public/downloads/Time-Attendance-Agent-Setup.exe`.
3. Set `AGENT_VERSION` in the server `.env` to the same higher version.

Linked agents download the installer and run it when their local version is older.
Managed per-machine installs may require Windows elevation for future updates,
depending on endpoint policy.

## Important Real-World Notes

- Tell staff that Windows activity is collected for time and attendance verification.
- Keep company shift times accurate because calculated work time depends on them.
- Keep employee-device links clean. One device should belong to the correct employee.
- Code-signing the Windows installer is recommended before wide rollout to reduce Windows SmartScreen warnings.
- The managed Windows agent is intentionally difficult for standard users to remove, but a local administrator can still uninstall it.
- Anomaly alerts are warnings, not automatic disciplinary findings. Admins should review the evidence before taking action.
- Backups are essential before clearing or resetting live data.

## Supporting Docs

- `docs/PRODUCTION.md` - production deployment checklist.
- `docs/windows-agent-installer.md` - installer notes.
- `docs/SAFEGUARDS.md` - detailed anomaly and safeguard notes.
- `docs/PHASE_1.md`, `docs/PHASE_2.md`, `docs/PHASE_3.md` - historical build notes.
