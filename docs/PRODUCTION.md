# Production deployment gates

The application refuses to boot in `APP_ENV=production` unless debugging is
disabled, HTTPS is configured, secure session cookies are enabled, and the
application key is strong. If `DB_SSL_VERIFY=true`, a database CA certificate
must also be configured.

## Required before business use

1. Deploy behind Apache, Nginx, or IIS with `public/` as the only document root.
   Do not use PHP's development server.
2. Install a trusted HTTPS certificate and redirect all HTTP traffic to HTTPS.
3. Copy `.env.production.example` to `.env` on the server and replace every
   placeholder. Never deploy the local `.env`.
4. Prefer a verified SSL/TLS database connection where the host provides a CA
   certificate: set `DB_SSL_CA` and keep `DB_SSL_VERIFY=true`. On shared hosting
   where the provider confirms no CA path is available or required for local
   database access, use `DB_SSL_CA=` and `DB_SSL_VERIFY=false`.
5. Use a dedicated least-privilege database account. It needs normal CRUD and
   migration permissions, not database-administrator access.
6. Rotate the administrator, demo, database, and hosting credentials used
   during development.
7. Configure encrypted daily database backups, a separate retention location,
   and a documented restore test. A backup is not accepted until it has been
   restored successfully.
8. Run migrations during deployment:

   ```powershell
   php -d extension=mbstring bin/migrate.php
   ```

9. Schedule maintenance daily:

   ```powershell
   php -d extension=mbstring bin/maintenance.php
   ```

10. Create a separate test database and run the test suite there. Never run
    disposable integration fixtures against production.
11. Monitor HTTPS availability, PHP errors, database connectivity, disk space,
    backup success, failed logins, and unusual API-rate-limit activity.
12. Complete privacy, employee-monitoring, data-retention, and employment-law
    reviews for every region in which the product will be used.

## Current enforced controls

- Native prepared statements and company-scoped data access.
- CSRF protection for browser mutations.
- Password hashing, throttled login, forced password changes, and session
  revocation after password reset.
- CSP, frame denial, MIME sniffing prevention, restrictive browser permissions,
  and HSTS when served over HTTPS.
- Per-user or per-device API throttling.
- Sanitized audit values with credential fields redacted.
- Database-configured attendance transitions and idempotent API events.
- Overnight and multiple same-day attendance sessions.
- Append-only corrections and missing-punch entries with reasons.
