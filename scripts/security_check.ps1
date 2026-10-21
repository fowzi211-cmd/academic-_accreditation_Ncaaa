param(
    [string]$ProjectPath = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'
$failures = [System.Collections.Generic.List[string]]::new()
$phpFiles = Get-ChildItem -LiteralPath $ProjectPath -Filter '*.php' -File -Recurse |
    Where-Object { $_.FullName -notmatch '[\\/](backups|uploads|temp_exports|vendor)[\\/]' }

$lintFailures = @($phpFiles | ForEach-Object {
    & php -l $_.FullName *> $null
    if ($LASTEXITCODE -ne 0) { "PHP syntax failure: $($_.FullName)" }
})
foreach ($lintFailure in $lintFailures) { $failures.Add($lintFailure) }

$secretMatches = & rg -n --glob '*.php' '(\$[A-Za-z_]*pass(word)?[A-Za-z_]*\s*=\s*["''][^"'']+|DB_PASSWORD\s*=\s*[^\s]+)' $ProjectPath 2>$null
if ($LASTEXITCODE -eq 0) {
    $failures.Add("Possible hard-coded credential(s):`n$($secretMatches -join "`n")")
}

$csrfCount = @(& rg -l 'function\s+requireValidCsrf|requireValidCsrf\s*\(' $ProjectPath -g '*.php' 2>$null).Count
if ($csrfCount -eq 0) {
    $failures.Add('No CSRF validation implementation was found.')
}

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Output "Security baseline checks passed for $($phpFiles.Count) PHP files."
