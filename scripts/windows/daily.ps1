<#
  Runs the daily jobs once (php artisan smm:daily): account check, backup, "daily jobs ran" record.
  The Windows task "\SocialMediaManager\Daily" runs this at 09:00, or as soon as the laptop is on after that.
  The last run's output is kept in <data folder>\logs\daily-last.log (and daily-last-error.log).
#>
param([string]$Php = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$php = Get-SmmPhp $Php
$logs = Join-Path $SmmData 'logs'
$run = Start-Process -FilePath $php -ArgumentList @('artisan', 'smm:daily', '--no-interaction') `
    -WorkingDirectory $SmmRepo -NoNewWindow -Wait -PassThru `
    -RedirectStandardOutput (Join-Path $logs 'daily-last.log') -RedirectStandardError (Join-Path $logs 'daily-last-error.log')
exit $run.ExitCode
