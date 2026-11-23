<#
  Starts SocialMedia Manager if it is not running, and with -Open shows it in the browser.
  The desktop shortcut "SocialMedia Manager" runs this with -Open. The app stops itself after
  30 minutes without use.
#>
param([switch]$Open, [string]$Php = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

if (-not (Test-SmmApp)) {
    if (Test-SmmPortBusy) {
        Write-Host "Port $SmmPort is used by another program. Close that program, or change SMM_PORT in $SmmEnv."
        exit 1
    }
    $php = Get-SmmPhp $Php
    $serve = Join-Path $PSScriptRoot 'serve.ps1'
    Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$serve`"", '-Php', "`"$php`"")
    $started = $false
    for ($i = 0; $i -lt 40; $i++) {
        Start-Sleep -Milliseconds 500
        if (Test-SmmApp) { $started = $true; break }
    }
    if (-not $started) {
        Write-Host "The app did not start. Details: $SmmData\logs\server.log"
        exit 1
    }
}

# Look for new photos and videos in the Library's folders, in the background (read only; SM-009).
try {
    $scanLog = Join-Path $SmmData 'logs\media-scan.log'
    $scanErr = Join-Path $SmmData 'logs\media-scan-error.log'
    Start-Process -FilePath (Get-SmmPhp $Php) -ArgumentList @('artisan', 'smm:media:scan') -WorkingDirectory $SmmRepo -WindowStyle Hidden -RedirectStandardOutput $scanLog -RedirectStandardError $scanErr | Out-Null
} catch {
    Write-Host "The Library scan did not start; use 'Look for new files' on the Library page."
}

Write-Host "SocialMedia Manager is running: $SmmUrl"
if ($Open) { Start-Process $SmmUrl }
exit 0
