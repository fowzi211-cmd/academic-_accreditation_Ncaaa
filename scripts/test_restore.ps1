param([Parameter(Mandatory=$true)][string]$BackupFile)
$ErrorActionPreference = 'Stop'
$resolved = (Resolve-Path -LiteralPath $BackupFile).Path
$expected = ((Get-Content -LiteralPath "$resolved.sha256" -Raw) -split '\s+')[0]
if ((Get-FileHash -LiteralPath $resolved -Algorithm SHA256).Hash -ne $expected) { throw 'Backup checksum mismatch.' }
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $php)) { $php = (Get-Command php -ErrorAction Stop).Source }
$configurationJson = & $php 'C:\quality_system\scripts\backup_config.php' 2>$null
if ($LASTEXITCODE -ne 0 -or -not $configurationJson) { throw 'Could not load restore configuration.' }
$admin = $configurationJson | ConvertFrom-Json
$mysql = 'C:\xampp\mysql\bin\mysql.exe'; $testDb = 'quality_system_restore_test'; $env:MYSQL_PWD = $admin.password
try {
    & $mysql --host=$($admin.host) --port=$($admin.port) --user=$($admin.user) -e "DROP DATABASE IF EXISTS ``$testDb``; CREATE DATABASE ``$testDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare restore test database.' }
    Get-Content -LiteralPath $resolved -Raw | & $mysql --host=$($admin.host) --port=$($admin.port) --user=$($admin.user) $testDb
    if ($LASTEXITCODE -ne 0) { throw 'Restore test failed.' }
    [int]$count = & $mysql --host=$($admin.host) --port=$($admin.port) --user=$($admin.user) --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$testDb';"
    if ($count -lt 1) { throw 'Restored database contains no tables.' }
    Write-Output "Restore verified with $count tables."
} finally {
    & $mysql --host=$($admin.host) --port=$($admin.port) --user=$($admin.user) -e "DROP DATABASE IF EXISTS ``$testDb``;" 2>$null
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}
