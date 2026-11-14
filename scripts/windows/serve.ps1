<#
  Runs the app's web server in the foreground (on 127.0.0.1 only) until it is stopped.
  The Windows task "\SocialMediaManager\Server" runs this at start-up; start.ps1 runs it hidden otherwise.
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
if (Test-Path $log) { Move-Item $log "$log.old" -Force }

# PHP's server must start inside the public folder, with Laravel's router given by full path.
# Start-Process does the redirection: PowerShell 5.1 would turn PHP's stderr lines into errors.
$router = Join-Path $SmmRepo 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
$server = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$SmmPort", "`"$router`"") `
    -WorkingDirectory (Join-Path $SmmRepo 'public') -NoNewWindow -Wait -PassThru `
    -RedirectStandardError $log -RedirectStandardOutput (Join-Path $SmmData 'logs\server-out.log')
exit $server.ExitCode
