<#
  Stops this copy's web server (only the PHP server on this copy's port; nothing else).
#>
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$task = Get-SmmServerTask
if ($task -and $task.State -eq 'Running') {
    Stop-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Server'
}

$servers = Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -like "*-S 127.0.0.1:$SmmPort *" }
foreach ($p in $servers) { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }

Write-Host 'SocialMedia Manager stopped.'
exit 0
