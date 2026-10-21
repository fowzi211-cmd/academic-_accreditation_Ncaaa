#Requires -RunAsAdministrator
$ErrorActionPreference = 'Stop'
$certificate = 'C:\quality_system_config\tls\quality-system.crt'
$storage = 'C:\quality_system_storage'
if (-not (Test-Path -LiteralPath $certificate)) { throw 'The localhost TLS certificate is missing.' }
& certutil.exe -addstore -f Root $certificate | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not trust the localhost TLS certificate.' }
icacls $storage /inheritance:e /grant:r 'SYSTEM:(OI)(CI)M' "$($env:USERNAME):(OI)(CI)M" /T /C | Out-Null
& 'C:\quality_system\scripts\install_apache_service.ps1'
& 'C:\quality_system\scripts\install_scheduled_maintenance.ps1'
Write-Output 'Production host configuration installed.'
Write-Output 'Open https://localhost/login.php'
