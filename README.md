# Quality System

Plain PHP/MySQL quality management application, including course specifications and course reports.

## Repository layout

- Root PHP files: application pages and compatibility endpoints.
- `app/`: controllers, services, repositories, security, and domain logic.
- `assets/` and `templates/`: styles and page templates.
- `database/migrations/`: schema migration source; no database dumps or user records.
- `scripts/`, `deployment/`, `tests/`, `docs/`: operations, deployment, tests, and documentation.
- `start_quality_system.bat`: local development launcher (expects `C:\quality_system`).
- `restore_database.bat`: manual restore utility; prompts for the database password. Running it may overwrite database data.

## Local setup

1. Install PHP with PDO MySQL, Composer, and a compatible MySQL/MariaDB server.
2. Install dependencies with `composer install`.
3. Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` as environment variables, using `.env.example` for variable names. The application does not automatically load `.env` files.
4. Alternatively, the Windows installation reads private configuration from `C:\quality_system_config\database.php`, outside this repository. Keep that folder private.
5. Review `README.txt` and `docs/MIGRATION_RECOVERY.md` for the existing installation and migration procedure. Do not run migrations or restore scripts against a database without reviewing them.
6. For local development, run `php -S localhost:8000 -t . router.php` and open http://localhost:8000/login.php. Some operations retain Windows installation paths; see the deployment documentation.

## Publication safeguards

This is a fresh source snapshot. Existing Git history, installed dependencies, backups, runtime uploads, local configuration, and database dumps are excluded. Backup-related PHP pages and BAT scripts are source code, not backup data.

The original GitHub Actions workflow is omitted because it contains a fixed test database password and requires a separate portability review before enabling hosted CI.

`.env.example` contains placeholders only. Supply your own credentials locally and never commit them. Review every future commit: `.gitignore` does not sanitize source code or files already tracked by Git.
