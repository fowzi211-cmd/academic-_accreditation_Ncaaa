#Requires -RunAsAdministrator
$ErrorActionPreference = 'Stop'
$transcript = 'C:\quality_system_storage\logs\service-recovery-test.log'
Start-Transcript -Path $transcript -Force | Out-Null
$backup = & 'C:\quality_system\scripts\backup_database.ps1'
if (-not $backup) { throw 'Pre-restart backup failed.' }
Restart-Service -Name QualitySystemMariaDB -Force
$ready = $false
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    Start-Sleep -Seconds 1
    if (Test-NetConnection -ComputerName 127.0.0.1 -Port 3306 -InformationLevel Quiet) { $ready = $true; break }
}
if (-not $ready) { throw 'MariaDB did not become ready after restart.' }
& 'C:\quality_system\scripts\test_restore.ps1' -BackupFile $backup
if ($LASTEXITCODE -ne 0) { throw 'Post-restart restore test failed.' }
Write-Output 'MariaDB restart and backup recovery test passed.'
Stop-Transcript | Out-Null
