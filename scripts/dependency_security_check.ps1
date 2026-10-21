$ErrorActionPreference = 'Stop'
composer validate --strict
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
composer audit --locked
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& "$PSScriptRoot\security_check.ps1" -ProjectPath (Split-Path -Parent $PSScriptRoot)
