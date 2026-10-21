@echo off
cd /d C:\quality_system
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\quality_system\scripts\start_mariadb.ps1"
if errorlevel 1 (
    echo MariaDB could not be started.
    pause
    exit /b 1
)
php -S localhost:8000 -t C:\quality_system C:\quality_system\router.php
pause
