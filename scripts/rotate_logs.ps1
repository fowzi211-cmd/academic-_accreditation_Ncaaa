param([string]$LogDirectory = 'C:\quality_system_storage\logs', [int]$RetentionDays = 90)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $LogDirectory)) { exit 0 }
$archiveDirectory = Join-Path $LogDirectory 'archive'
New-Item -ItemType Directory -Force -Path $archiveDirectory | Out-Null
$cutoff = (Get-Date).Date
Get-ChildItem -LiteralPath $LogDirectory -File | Where-Object { $_.LastWriteTime -lt $cutoff -and $_.Extension -in '.log','.jsonl' } | ForEach-Object {
    $archive = Join-Path $archiveDirectory ($_.Name + '.' + $_.LastWriteTime.ToString('yyyyMMdd-HHmmss') + '.zip')
    Compress-Archive -LiteralPath $_.FullName -DestinationPath $archive -CompressionLevel Optimal
    Clear-Content -LiteralPath $_.FullName
}
Get-ChildItem -LiteralPath $archiveDirectory -File -Filter '*.zip' | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$RetentionDays) } | Remove-Item -Force
