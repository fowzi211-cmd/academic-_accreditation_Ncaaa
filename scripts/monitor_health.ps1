param([int]$MinimumFreeGigabytes=5,[int]$MaximumBackupAgeHours=26,[int]$FailedLoginThreshold=20)
$ErrorActionPreference='Stop';$issues=[Collections.Generic.List[string]]::new();$result=[ordered]@{time=(Get-Date).ToUniversalTime().ToString('o')}
foreach($name in 'QualitySystemApache','QualitySystemMariaDB'){$service=Get-Service $name -ErrorAction SilentlyContinue;$result[$name]=$service.Status.ToString();if($service.Status -ne 'Running'){$issues.Add("$name is not running")}}
$latest=Get-ChildItem C:\quality_system_storage\backups\quality_system-*.sql -ErrorAction SilentlyContinue|Sort-Object LastWriteTime -Descending|Select-Object -First 1
$result.backup_age_hours=if($latest){[math]::Round(((Get-Date)-$latest.LastWriteTime).TotalHours,2)}else{$null};if(!$latest -or $result.backup_age_hours -gt $MaximumBackupAgeHours){$issues.Add('Database backup is missing or stale')}
$drive=Get-PSDrive -Name C;$result.free_gigabytes=[math]::Round($drive.Free/1GB,2);if($result.free_gigabytes -lt $MinimumFreeGigabytes){$issues.Add('Storage capacity is below threshold')}
$php='C:\xampp\php\php.exe';$probe=&$php C:\quality_system\scripts\health_probe.php 2>$null|ConvertFrom-Json;$result.database=$probe.database;$result.failed_logins_15m=$probe.failed_logins_15m;if($probe.failed_logins_15m -ge $FailedLoginThreshold){$issues.Add('Failed-login spike detected')}
$status=[int](&curl.exe -s -o NUL -w '%{http_code}' https://localhost/login.php);$result.https_status=$status;if($status -ne 200){$issues.Add("HTTPS login check returned $status")}
$result.healthy=$issues.Count-eq0;$result.issues=$issues;$line=$result|ConvertTo-Json -Compress;Add-Content -LiteralPath C:\quality_system_storage\logs\health-monitor.jsonl -Value $line
if($issues.Count){throw($issues-join'; ')};Write-Output $line
