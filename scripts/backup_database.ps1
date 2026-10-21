param([string]$OutputDirectory = 'C:\quality_system_storage\backups')
$ErrorActionPreference = 'Stop'
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $php)) { $php = (Get-Command php -ErrorAction Stop).Source }
$configurationJson = & $php 'C:\quality_system\scripts\backup_config.php' 2>$null
if ($LASTEXITCODE -ne 0 -or -not $configurationJson) { throw 'Could not load backup configuration.' }
$configuration = $configurationJson | ConvertFrom-Json
$admin = $configuration
$app = $configuration
$dump = 'C:\xampp\mysql\bin\mysqldump.exe'
if (-not (Test-Path -LiteralPath $dump)) { throw "mysqldump was not found: $dump" }
New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$target = Join-Path $OutputDirectory "$($app.name)-$(Get-Date -Format 'yyyyMMdd-HHmmss').sql"
$env:MYSQL_PWD = $admin.password
try {
    & $dump --host=$($admin.host) --port=$($admin.port) --user=$($admin.user) --single-transaction --routines --events --triggers --hex-blob --skip-add-drop-database $app.name --result-file=$target
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $target) -or (Get-Item -LiteralPath $target).Length -eq 0) { throw 'Database backup failed.' }
} finally { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
$hash = (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash
Set-Content -LiteralPath "$target.sha256" -Value "$hash  $([IO.Path]::GetFileName($target))" -Encoding ascii
Write-Output $target
