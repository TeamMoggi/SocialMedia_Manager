<#
  Runs the publisher once (php artisan smm:publish-due). The one-time wake-up tasks made by wake-at.ps1 run this
  at the moments posts are due. The last run's output is kept in <data folder>\logs\publish-last.log.
#>
param([string]$Php = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$php = Get-SmmPhp $Php
$logs = Join-Path $SmmData 'logs'
$run = Start-Process -FilePath $php -ArgumentList @('artisan', 'smm:publish-due', '--no-interaction') `
    -WorkingDirectory $SmmRepo -NoNewWindow -Wait -PassThru `
    -RedirectStandardOutput (Join-Path $logs 'publish-last.log') -RedirectStandardError (Join-Path $logs 'publish-last-error.log')
exit $run.ExitCode
