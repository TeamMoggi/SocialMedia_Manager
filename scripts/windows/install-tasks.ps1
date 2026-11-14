<#
  Installs the two Windows tasks that keep SocialMedia Manager running (App copy only):
    \SocialMediaManager\Server  at start-up: the app on http://127.0.0.1:<port>, no time limit, restarts if it stops
    \SocialMediaManager\Tick    at start-up and from now on, every minute: the timed jobs
  Both run "whether you are signed in or not" without storing your password (S4U), so no window flashes.
  Needs one administrator approval (the Windows "Do you want to allow..." prompt).
#>
param([string]$Php = '', [string]$User = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

if (-not $SmmIsApp) { throw 'Install the Windows tasks only from the App copy.' }
$php = Get-SmmPhp $Php
if (-not $User) { $User = "$env:USERDOMAIN\$env:USERNAME" }

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$isAdmin = ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host 'Asking Windows for permission to install the tasks...'
    $argList = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Php', "`"$php`"", '-User', "`"$User`"")
    $p = Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $argList -Wait -PassThru
    exit $p.ExitCode
}

$ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$principal = New-ScheduledTaskPrincipal -UserId $User -LogonType S4U -RunLevel Limited

# Server: one long-running process; no time limit (the default 72 hours would stop the app silently).
$serverAction = New-ScheduledTaskAction -Execute $ps -WorkingDirectory $SmmRepo `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$PSScriptRoot\serve.ps1`" -Php `"$php`""
$serverSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Server' -Action $serverAction -Trigger (New-ScheduledTaskTrigger -AtStartup) `
    -Principal $principal -Settings $serverSettings -Description "SocialMedia Manager: the app at $SmmUrl" -Force | Out-Null

# Tick: every minute from start-up, and from now on without a restart. Never wakes the laptop.
$tickAction = New-ScheduledTaskAction -Execute $ps -WorkingDirectory $SmmRepo `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$PSScriptRoot\tick.ps1`" -Php `"$php`""
$everyMinute = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)).Repetition
$atStartup = New-ScheduledTaskTrigger -AtStartup
$atStartup.Repetition = $everyMinute
$fromNow = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) -RepetitionInterval (New-TimeSpan -Minutes 1)
$tickSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Tick' -Action $tickAction -Trigger @($atStartup, $fromNow) `
    -Principal $principal -Settings $tickSettings -Description 'SocialMedia Manager: timed jobs every minute' -Force | Out-Null

Start-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Server'
Write-Host "Installed. The app starts with Windows at $SmmUrl and its timed jobs run every minute."
exit 0
