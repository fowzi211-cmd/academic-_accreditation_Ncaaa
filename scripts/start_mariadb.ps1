$ErrorActionPreference = 'Stop'

$server = 'C:\xampp\mysql\bin\mysqld.exe'
$configuration = 'C:\xampp\mysql\bin\my.ini'

if (-not (Test-Path -LiteralPath $server) -or -not (Test-Path -LiteralPath $configuration)) {
    throw 'The XAMPP MariaDB installation was not found.'
}

$listener = Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue
if (-not $listener) {
    Start-Process -FilePath $server `
        -ArgumentList @("--defaults-file=$configuration", '--standalone') `
        -WorkingDirectory (Split-Path -Parent $server) `
        -WindowStyle Hidden | Out-Null
}

for ($attempt = 0; $attempt -lt 30; $attempt++) {
    if (Test-NetConnection -ComputerName '127.0.0.1' -Port 3306 -InformationLevel Quiet -WarningAction SilentlyContinue) {
        Write-Output 'MariaDB is ready on 127.0.0.1:3306.'
        exit 0
    }
    Start-Sleep -Milliseconds 500
}

throw 'MariaDB did not become ready within 15 seconds.'
