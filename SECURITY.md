# Security and deployment notes

## Immediate operating rules

- Keep the application restricted to localhost or a trusted internal network until the security migration is complete.
- Do not use PHP's built-in server for production deployment.
- Set the web-server document root to a dedicated `public` directory when that migration is complete.
- Never place database dumps, uploaded documents, temporary exports, credentials, or logs under the public document root.

## Configuration

Database settings are read from `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`.
For local operation, `C:\quality_system_config\database.php` contains the CRUD-only application account. `database-admin.php` is reserved for the CLI migration runner. Protect both files with operating-system permissions.

Runtime files are stored beneath `C:\quality_system_storage` by default. Override this with `QUALITY_STORAGE_ROOT`. Uploaded files are delivered only through the authenticated download controller.

`APP_PRODUCTION` defaults to enabled. Set `APP_PRODUCTION=0` only in a private development environment when browser-visible PHP errors are intentionally required.

## Required credential work

The historical database credentials must be treated as compromised because they were previously stored in source files and local snapshots.

The application account and administrator password were rotated on 2026-08-26. The application account is limited to `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on `quality_system`. Schema changes must run through `php database/migrate.php`.

## Destructive requests

All POST endpoints require a session-bound CSRF token. All destructive actions use explicit POST forms; direct GET requests are rejected with HTTP 405.

## Recovery

The pre-change recovery snapshot is stored in `C:\quality_system_backups`. It contains historical secrets and must remain access-restricted.
