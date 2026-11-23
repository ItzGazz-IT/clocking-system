# Windows Clocking Agent Installer

Build the installer from the project root:

```powershell
.\installer\build-agent-installer.ps1 -Version 1.2.1
```

The installer is created at:

```text
dist\Time-Attendance-Agent-Setup.exe
```

## What The Installer Does

- Installs the agent per machine under `Program Files`.
- Requires administrator rights to install or uninstall.
- Stores writable device settings under `%PROGRAMDATA%\Time Attendance Tracking\Agent`.
- Adds a Start Menu shortcut under `Time Attendance Tracking`.
- Offers an optional desktop shortcut.
- Starts the Time Attendance Agent when Windows starts through machine-wide startup.
- Launches the agent after install.
- Leaves the API URL blank until configured.

## Staff Setup Flow

1. An administrator runs `Time-Attendance-Agent-Setup.exe`.
2. Finish the installer.
3. Open the tray icon settings.
4. Enter the server URL, employee username, and password.
5. Click `Link this device`.
6. After linking, use `Test connection` if needed.

Startup is automatic. The settings window shows that the agent starts with Windows, but normal users do not need to manage that option.

The agent opens a taskbar-visible confirmation window five minutes before clock-in and clock-out. Attendance is recorded only when the employee confirms; simply running the agent never creates an attendance session.

## Notes

The installer is not code-signed yet, so Windows may show a SmartScreen warning on some PCs.
Code signing is the next polish step before a broad rollout.
