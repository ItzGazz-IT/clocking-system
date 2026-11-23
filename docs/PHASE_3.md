# Phase 3

Phase 3 adds employee self-service, identity lifecycle management, scoped
access, record maintenance, and audited attendance corrections.

## User lifecycle

- Administrators provision a login only for an existing active employee.
- Each account receives one system role: Administrator or Employee.
- A cryptographically generated temporary password is displayed once.
- New and reset accounts must change their password before accessing the app or API.
- Administrators can reset passwords, change roles, disable, or enable accounts.
- The final active administrator cannot be disabled, demoted, or archived.

## Employee experience

- Employees see a self-only clock panel with no employee selector.
- Employees can view their immutable event timeline and daily attendance summary.
- Employees see only their own attendance information.
- Company-wide dashboard metrics and activity are scoped to the viewer’s
  authorized employee set.

## Record lifecycle

- Departments, shifts, and employees can be edited.
- Archiving uses soft deletion and is blocked while active dependencies remain.
- Archiving an employee also ends shift assignments and disables their login.

## Corrections

Corrections never update or delete the source event. A new correction event:

- references the original event;
- records corrected event type and time;
- requires a reason;
- records the administrator and request metadata;
- recalculates the daily projection from the effective event timeline.

## Database

Run tracked migrations with:

```powershell
php -d extension=mbstring bin/migrate.php
```
