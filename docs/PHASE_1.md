# Phase 1 file guide

This phase is intentionally a foundation rather than a complete attendance
product. The API contracts exist, but return HTTP 501 until the attendance
domain service is implemented in the approved next phase.

## Root and setup

| File | Purpose |
|---|---|
| `.env.example` | Safe template for application, database, and session environment variables. |
| `.gitignore` | Excludes secrets, dependencies, logs, uploads, and editor state. |
| `composer.json` | PHP 8.3 requirements and PSR-4 autoloading for the `App` namespace. |
| `README.md` | XAMPP installation and first-administrator setup instructions. |
| `docs/PHASE_1.md` | This complete file inventory and architectural boundary guide. |
| `server.php` | Routes PHP development-server requests while serving existing public assets directly. |
| `bootstrap/app.php` | Defines the base path, loads Composer/environment values, and boots the application. |
| `logs/.gitkeep` | Retains the runtime log directory without committing logs. |
| `uploads/.gitkeep` | Retains the future upload directory without committing customer files. |

## Configuration

| File | Purpose |
|---|---|
| `config/app.php` | Application identity, URL, environment, debugging, timezone, and key. |
| `config/database.php` | PDO MySQL connection options with native prepared statements. |
| `config/security.php` | Password, login-throttling, and CSRF policy. |
| `config/session.php` | Secure cookie and session-regeneration policy. |

## Core framework

| File | Purpose |
|---|---|
| `app/helpers/functions.php` | Environment/config access, safe HTML escaping, CSRF fields, and old input helpers. |
| `app/Core/Application.php` | Boots configuration/session/routes and converts exceptions into safe responses. |
| `app/Core/Config.php` | Loads configuration files and resolves dot-notation keys. |
| `app/Core/Controller.php` | Reusable base for HTML and JSON controllers. |
| `app/Core/Database.php` | Lazy singleton PDO connection with sanitized connection failures. |
| `app/Core/Environment.php` | Minimal `.env` loader with no secret committed to source. |
| `app/Core/HttpException.php` | Carries intentional HTTP status errors through the stack. |
| `app/Core/Model.php` | Supplies models with the shared PDO connection. |
| `app/Core/Request.php` | Normalizes URI, query/form/JSON input, client metadata, and route attributes. |
| `app/Core/Response.php` | Sends HTML, JSON, redirects, status codes, and headers. |
| `app/Core/Route.php` | Immutable route definition. |
| `app/Core/Router.php` | Matches methods/paths/parameters and executes middleware pipelines. |
| `app/Core/Session.php` | Starts hardened sessions, regenerates IDs, and creates CSRF tokens. |
| `app/Core/Validator.php` | Central input validation entry point, initially for login. |
| `app/Core/View.php` | Renders isolated PHP view templates. |
| `app/Contracts/Middleware.php` | Common contract implemented by every middleware component. |

## Authentication and authorization

| File | Purpose |
|---|---|
| `app/Auth/Auth.php` | Resolves, signs in, and signs out the current user. |
| `app/Auth/UserIdentity.php` | Immutable authenticated-user and permission representation. |
| `app/Models/User.php` | Prepared user, role, permission, login, and password-hash queries. |
| `app/Services/AuthenticationService.php` | Verifies and rehashes passwords, throttles failures, and creates a session. |
| `app/Services/LoginThrottleService.php` | Database-backed login attempt limiting across PHP processes. |
| `app/Services/AuditService.php` | Appends actor, machine, request, before/after, and reason data to the audit trail. |
| `app/Middleware/AuthMiddleware.php` | Requires a signed-in browser user. |
| `app/Middleware/GuestMiddleware.php` | Keeps signed-in users away from guest-only screens. |
| `app/Middleware/CsrfMiddleware.php` | Rejects forged state-changing browser requests. |
| `app/Middleware/PermissionMiddleware.php` | Enforces named RBAC permissions and fails closed. |
| `app/Middleware/ApiAuthMiddleware.php` | Accepts authenticated sessions or identifier/secret device bearer tokens. |

## Controllers, routes, and views

| File | Purpose |
|---|---|
| `app/Controllers/AuthController.php` | Displays login and handles audited login/logout actions. |
| `app/Controllers/DashboardController.php` | Protected Phase 1 dashboard entry point. |
| `app/Controllers/Api/AttendanceController.php` | Reserves clock, break, Windows-event, and attendance API contracts. |
| `app/Controllers/Api/EmployeeController.php` | Reserves the employee API contract. |
| `routes/web.php` | Browser routes and their CSRF/auth/permission middleware. |
| `routes/api.php` | Device-protected REST route definitions under `/api`. |
| `app/Views/auth/login.php` | Escaped, accessible login form. |
| `app/Views/dashboard/index.php` | Authenticated foundation landing screen. |
| `app/Views/errors/http.php` | Escaped error page with no production stack trace. |

## Public assets and API boundary

| File | Purpose |
|---|---|
| `public/index.php` | Only executable web entry point. |
| `public/.htaccess` | Sends non-file Apache requests to the router and disables directory indexes. |
| `public/assets/css/app.css` | Small responsive foundation stylesheet. |
| `public/assets/js/app.js` | Strict-mode JavaScript entry point for later phases. |
| `public/assets/images/.gitkeep` | Retains the empty public image directory. |
| `api/README.md` | Reserves API documentation while preventing middleware-bypassing scripts. |

## Database

| File | Purpose |
|---|---|
| `database/schema.sql` | Normalized MySQL 8 schema, constraints, indexes, soft deletion, event history, RBAC, devices, settings, and audit data. |
| `database/seed.sql` | Repeatable system roles, permissions, attendance event types, and Windows event types. |

The requested leave data is represented by `leave_types` and `leave_requests`,
separating policy from employee transactions. Attendance and Windows events are
append-only and idempotent; corrections reference the original event. The
`attendance` and `breaks` rows are projections that can be recalculated from
that event history, so source evidence is never overwritten.
