# Migration and recovery strategy

Schema migrations are forward-only because MariaDB DDL is not reliably transactional. Before every production migration, run `scripts/backup_database.ps1`, retain its SHA-256 sidecar, and verify it with `scripts/test_restore.ps1` on an isolated database. Apply `php database/migrate.php` only after that verification.

Rollback means restoring the verified pre-migration backup, not attempting partial reverse DDL. Stop web traffic, preserve the failed database for investigation, restore into a new database, validate table counts and application smoke tests, then switch the application configuration. Never overwrite the only copy of either database.
