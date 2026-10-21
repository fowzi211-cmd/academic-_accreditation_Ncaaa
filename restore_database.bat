@echo off
setlocal

set MYSQL="C:\Program Files\MySQL\MySQL Server 9.7\bin\mysql.exe"
set DB_NAME=quality_system
set DB_USER=root

echo.
echo ==========================================
echo RESTORE DATABASE: %DB_NAME%
echo ==========================================
echo.
echo WARNING: This will overwrite data in the database if the backup contains existing tables.
echo.
echo Drag or type the full path of the .sql backup file below.
echo Example: C:\quality_system_backups\quality_system_2026-05-14_10-30-00.sql
echo.

set /p BACKUP_FILE=Enter backup file path: 

echo.
echo Restoring database from:
echo %BACKUP_FILE%
echo.

%MYSQL% -u %DB_USER% -p %DB_NAME% < "%BACKUP_FILE%"

echo.
echo Restore completed.
echo.

pause