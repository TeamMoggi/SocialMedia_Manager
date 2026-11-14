<#
  Runs the app's timed jobs once (php artisan schedule:run). The Windows task "\SocialMediaManager\Tick"
  runs this every minute; Laravel decides which jobs are due. The last run's output is kept in
  <data folder>\logs\tick-last.log (and tick-last-error.log).
#>
param([string]$Php = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$php = Get-SmmPhp $Php
$logs = Join-Path $SmmData 'logs'
$run = Start-Process -FilePath $php -ArgumentList @('artisan', 'schedule:run', '--no-interaction') `
    -WorkingDirectory $SmmRepo -NoNewWindow -Wait -PassThru `
    -RedirectStandardOutput (Join-Path $logs 'tick-last.log') -RedirectStandardError (Join-Path $logs 'tick-last-error.log')
exit $run.ExitCode
