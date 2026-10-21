param([string]$HostName = 'localhost')
$ErrorActionPreference = 'Stop'
$httpStatus = [int](& curl.exe -s -o NUL -w '%{http_code}' "http://$HostName/login.php")
if ($httpStatus -notin 301,302,307,308) { throw "HTTP redirect failed: $httpStatus" }
$headerFile = [System.IO.Path]::GetTempFileName()
$bodyFile = [System.IO.Path]::GetTempFileName()
try {
    $httpsStatus = [int](& curl.exe -k -s -D $headerFile -o $bodyFile -w '%{http_code}' "https://$HostName/login.php")
    if ($httpsStatus -ne 200) { throw "HTTPS login failed: $httpsStatus" }
    if ((Get-Content -Raw $headerFile) -notmatch '(?im)^Strict-Transport-Security:') { throw 'HSTS header is missing.' }
    if ((Get-Content -Raw $bodyFile) -notmatch 'name="_csrf_token"') { throw 'CSRF field is missing.' }
} finally {
    Remove-Item -LiteralPath $headerFile,$bodyFile -Force -ErrorAction SilentlyContinue
}
foreach ($path in @('/database/migrate.php','/scripts/backup_database.ps1','/vendor/autoload.php','/composer.json')) {
    $status = [int](& curl.exe -k -s -o NUL -w '%{http_code}' "https://$HostName$path")
    if ($status -notin 403,404) { throw "Protected path exposed: $path ($status)" }
}
$env:QUALITY_E2E_INSECURE_TLS = '1'
try { php C:\quality_system\scripts\http_e2e.php "https://$HostName" } finally { Remove-Item Env:QUALITY_E2E_INSECURE_TLS -ErrorAction SilentlyContinue }
if ($LASTEXITCODE -ne 0) { throw 'HTTPS end-to-end suite failed.' }
Write-Output 'Production HTTPS checks passed.'
