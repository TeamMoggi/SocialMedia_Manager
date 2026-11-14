<#
  Starts SocialMedia Manager if it is not running, and with -Open shows it in the browser.
#>
param([switch]$Open, [string]$Php = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

if (-not (Test-SmmApp)) {
    if (Test-SmmPortBusy) {
        Write-Host "Port $SmmPort is used by another program. Close that program, or change SMM_PORT in $SmmEnv."
        exit 1
    }
    $task = Get-SmmServerTask
    if ($task) {
        Start-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Server'
    }
    else {
        $php = Get-SmmPhp $Php
        $serve = Join-Path $PSScriptRoot 'serve.ps1'
        Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$serve`"", '-Php', "`"$php`"")
    }
    $started = $false
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Milliseconds 500
        if (Test-SmmApp) { $started = $true; break }
    }
    if (-not $started) {
        Write-Host "The app did not start. Details: $SmmData\logs\server.log"
        exit 1
    }
}

Write-Host "SocialMedia Manager is running: $SmmUrl"
if ($Open) { Start-Process $SmmUrl }
exit 0
