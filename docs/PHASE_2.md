# Phase 2

Phase 2 adds the operational attendance vertical while preserving the Phase 1
security and audit boundaries.

## Delivered

- Company-scoped department, shift, and employee creation and directories.
- Effective-dated shift assignment when an employee is created.
- Secure current-password verification and password replacement.
- Database-configured attendance state transitions.
- Append-only clock, break, and lunch events with UUID idempotency.
- Transactional projections for current state, breaks, worked time, and overtime.
- Session and device-authenticated REST endpoints.
- Windows event ingestion for assigned devices.
- Live dashboard metrics and recent activity.
- Additive, repeatable database migration and executable test suite.
- Tracked `bin/migrate.php` command for safe future schema evolution.

## API

Authenticated endpoints:

- `POST /api/clockin`
- `POST /api/clockout`
- `POST /api/break/start`
- `POST /api/break/end`
- `POST /api/lunch/start`
- `POST /api/lunch/end`
- `POST /api/windows-event`
- `GET /api/attendance`
- `GET /api/employee`

Mutation requests accept an optional UUID `idempotency_key`; Windows events
require it. Device bearer tokens use `identifier.secret` and only act for the
employee assigned to that device.

## Tests

Run:

```powershell
php -d extension=mbstring composer.phar test
```
