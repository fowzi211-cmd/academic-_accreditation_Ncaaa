#Requires -RunAsAdministrator
param([string]$ServiceName = 'QualitySystemApache')
$ErrorActionPreference = 'Stop'
$httpd = 'C:\xampp\apache\bin\httpd.exe'
if (-not (Test-Path -LiteralPath $httpd)) { throw 'XAMPP Apache was not found.' }
& $httpd -t
if ($LASTEXITCODE -ne 0) { throw 'Apache configuration validation failed.' }
$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if (-not $service) {
    $binaryPath = '"' + $httpd + '" -k runservice -n "' + $ServiceName + '"'
    New-Service -Name $ServiceName -BinaryPathName $binaryPath -DisplayName 'Quality System Apache' -Description 'HTTPS web server for the Quality Management System' -StartupType Automatic | Out-Null
}
Set-Service -Name $ServiceName -StartupType Automatic
$existing = Get-NetTCPConnection -LocalPort 80,443 -State Listen -ErrorAction SilentlyContinue
foreach ($listener in $existing) {
    $process = Get-Process -Id $listener.OwningProcess -ErrorAction SilentlyContinue
    if ($process -and $process.ProcessName -ne 'httpd') { throw "Port $($listener.LocalPort) is occupied by $($process.ProcessName)." }
}
Start-Service -Name $ServiceName
$service = Get-Service -Name $ServiceName
Write-Output "$($service.Name): $($service.Status); startup: $($service.StartType)."
