<#
  Runs the app (on 127.0.0.1 only) until nobody has used it for 30 minutes: php artisan smm:serve.
  start.ps1 runs this hidden; the desktop shortcut "SocialMedia Manager" calls start.ps1 -Open.
#>
param([string]$Php = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$php = Get-SmmPhp $Php
if (Test-SmmPortBusy) {
    Write-Host "Port $SmmPort is already in use; not starting a second server."
    exit 0
}

$log = Join-Path $SmmData 'logs\server.log'
if ((Test-Path $log) -and ((Get-Item $log).Length -gt 5MB)) { Move-Item $log "$log.old" -Force }

# Start-Process does the redirection: PowerShell 5.1 would turn PHP's stderr lines into errors.
$watch = Start-Process -FilePath $php -ArgumentList @('artisan', 'smm:serve', '--no-interaction') `
    -WorkingDirectory $SmmRepo -NoNewWindow -Wait -PassThru `
    -RedirectStandardOutput (Join-Path $SmmData 'logs\serve-last.log') -RedirectStandardError (Join-Path $SmmData 'logs\serve-last-error.log')
exit $watch.ExitCode
