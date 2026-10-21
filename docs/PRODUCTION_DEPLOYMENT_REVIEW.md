# Production deployment review

## Required gates

- Use HTTPS behind a supported web server; do not expose the PHP development server.
- Set `APP_PRODUCTION=1`, a unique `APP_AUDIT_KEY`, and credentials outside the web root.
- Run Composer audit, migration status, PHPUnit, security lint, database integrity audit, and HTTP/browser smoke tests.
- Create and test-restore a database backup before deploying.
- Confirm the application account has only SELECT, INSERT, UPDATE, and DELETE.
- Configure `QualitySystemMariaDB` with automatic startup from an elevated PowerShell session.
- Restrict ACLs on configuration, backups, uploads, exports, logs, and restore artifacts.
- Configure log rotation, backup retention, monitoring, and alerting.
- Verify CSP, secure cookies, CSRF, login throttling, authorization denials, upload MIME checks, protected downloads, and POST-only deletion.

The compatibility router is a transition layer. New behavior belongs in `app/` services/controllers/repositories; existing endpoint names remain adapters until migrated module by module.

## Local XAMPP production host

Run `scripts\install_production_host.ps1` once from an Administrator PowerShell. It trusts the localhost certificate, installs the automatic `QualitySystemApache` service, applies external-storage ACLs, and registers daily database-backup and log-rotation tasks. The application is then available at `https://localhost/`; HTTP redirects permanently to HTTPS.
