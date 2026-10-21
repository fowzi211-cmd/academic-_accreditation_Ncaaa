#Requires -RunAsAdministrator
param([string]$TaskPrefix = 'QualitySystem')
$ErrorActionPreference = 'Stop'
$transcript = 'C:\quality_system_storage\logs\scheduled-maintenance-install.log'
Start-Transcript -Path $transcript -Force | Out-Null
$powerShell = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$backupAction = New-ScheduledTaskAction -Execute $powerShell -Argument '-NoProfile -ExecutionPolicy Bypass -File "C:\quality_system\scripts\backup_database.ps1"'
$backupTrigger = New-ScheduledTaskTrigger -Daily -At '02:00'
$logAction = New-ScheduledTaskAction -Execute $powerShell -Argument '-NoProfile -ExecutionPolicy Bypass -File "C:\quality_system\scripts\rotate_logs.ps1"'
$logTrigger = New-ScheduledTaskTrigger -Daily -At '03:00'
$healthAction = New-ScheduledTaskAction -Execute $powerShell -Argument '-NoProfile -ExecutionPolicy Bypass -File "C:\quality_system\scripts\monitor_health.ps1"'
$healthTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName "$TaskPrefix-DatabaseBackup" -Action $backupAction -Trigger $backupTrigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Register-ScheduledTask -TaskName "$TaskPrefix-LogRotation" -Action $logAction -Trigger $logTrigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Register-ScheduledTask -TaskName "$TaskPrefix-HealthMonitor" -Action $healthAction -Trigger $healthTrigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Write-Output 'Scheduled daily database backup at 02:00, log rotation at 03:00, and health monitoring every five minutes.'
Stop-Transcript | Out-Null
