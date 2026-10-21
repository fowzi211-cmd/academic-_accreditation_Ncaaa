#Requires -RunAsAdministrator
param([string]$ServiceName = 'QualitySystemMariaDB')

$ErrorActionPreference = 'Stop'
$mysqld = 'C:\xampp\mysql\bin\mysqld.exe'
$defaults = 'C:\xampp\mysql\bin\my.ini'

if (-not (Test-Path -LiteralPath $mysqld) -or -not (Test-Path -LiteralPath $defaults)) {
    throw 'XAMPP MariaDB files were not found.'
}

$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if (-not $service) {
    # XAMPP builds vary in how their --install parser handles named services.
    # Register the documented MariaDB service command line directly with SCM.
    $binaryPath = '"' + $mysqld + '" --defaults-file="' + $defaults + '" ' + $ServiceName
    New-Service -Name $ServiceName `
        -BinaryPathName $binaryPath `
        -DisplayName 'Quality System MariaDB' `
        -Description 'MariaDB database service for the Quality Management System' `
        -StartupType Automatic | Out-Null
    $service = Get-Service -Name $ServiceName -ErrorAction Stop
}

Set-Service -Name $ServiceName -StartupType Automatic

if ($service.Status -ne 'Running') {
    $listeners = Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue
    foreach ($listener in $listeners) {
        $process = Get-Process -Id $listener.OwningProcess -ErrorAction SilentlyContinue
        if ($process -and [IO.Path]::GetFullPath($process.Path) -eq [IO.Path]::GetFullPath($mysqld)) {
            Stop-Process -Id $process.Id -Force
            $process.WaitForExit()
        } elseif ($process) {
            throw "Port 3306 is occupied by $($process.ProcessName); the MariaDB service was registered but cannot start."
        }
    }
    Start-Service -Name $ServiceName
}

$service = Get-Service -Name $ServiceName
if ($service.Status -ne 'Running') {
    throw "$ServiceName was registered but did not reach Running state. Check C:\xampp\mysql\data\mysql_error.log."
}

Write-Output "$($service.Name): $($service.Status); startup: Automatic."
