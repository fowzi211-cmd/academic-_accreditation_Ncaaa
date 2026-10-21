QUALITY MANAGEMENT SYSTEM

Production services:
QualitySystemApache (Automatic)
QualitySystemMariaDB (Automatic)

Login:
https://localhost/login.php

Backup:
cd C:\quality_system
powershell -ExecutionPolicy Bypass -File scripts\backup_database.ps1

Health Check:
https://localhost/health_check.php

Protected storage:
C:\quality_system_storage

Database migrations:
php database\migrate.php

XAMPP Apache serves the application through HTTPS and redirects HTTP to HTTPS. The development launcher remains available as a fallback and must not be exposed as a production server.

Automated tests:
composer test
composer http-test
powershell -ExecutionPolicy Bypass -File scripts\production_https_test.ps1

Security and syntax checks:
powershell -ExecutionPolicy Bypass -File scripts\security_check.ps1

Operational health monitor:
powershell -ExecutionPolicy Bypass -File scripts\monitor_health.ps1
C:\quality_system_storage\logs\health-monitor.jsonl

Operations and deployment guidance:
docs\MIGRATION_RECOVERY.md
docs\PRODUCTION_DEPLOYMENT_REVIEW.md
docs\TASK_MODULE_ARCHITECTURE.md
docs\PROGRAM_SPECIFICATION_ARCHITECTURE.md
docs\COURSE_REPORT_ARCHITECTURE.md

New application logic belongs under app\Controllers, app\Services, app\Repositories, app\Security, app\Http, and app\Support. Existing PHP endpoints remain compatibility adapters during the incremental migration.

Do not delete:
db.php
auth_check.php
role_check.php
nav.php
log_activity.php
C:\quality_system_config
C:\quality_system_storage
