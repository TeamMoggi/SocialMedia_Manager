<#
  Sets SocialMedia Manager up to run only when needed (App copy only):
    - a desktop shortcut "SocialMedia Manager" that starts the app and opens it (it stops itself after
      30 minutes without use);
    - one Windows task, \SocialMediaManager\Daily, that runs the daily jobs at 09:00, or as soon as the
      laptop is on after a missed 09:00. It runs without a window and without storing your password
      (S4U), never wakes the laptop, and has a 1-hour limit.
  Removes the older always-on tasks (Server, Tick) if they exist. Needs one administrator approval.
#>
param([string]$Php = '', [string]$User = '')
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

if (-not $SmmIsApp) { throw 'Install the Windows task and shortcut only from the App copy.' }
$php = Get-SmmPhp $Php
if (-not $User) { $User = "$env:USERDOMAIN\$env:USERNAME" }
$ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$isAdmin = ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    # The shortcut is made as you, on your desktop; only the task needs the administrator prompt.
    $shell = New-Object -ComObject WScript.Shell
    $link = $shell.CreateShortcut((Join-Path ([Environment]::GetFolderPath('Desktop')) 'SocialMedia Manager.lnk'))
    $link.TargetPath = $ps
    $link.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$PSScriptRoot\start.ps1`" -Open"
    $link.WorkingDirectory = $SmmRepo
    $link.IconLocation = (Join-Path $SmmRepo 'public\brand\app.ico') + ',0'
    $link.WindowStyle = 7
    $link.Description = 'Open SocialMedia Manager (Moggies International)'
    $link.Save()
    Write-Host 'Desktop shortcut "SocialMedia Manager" created.'

    Write-Host 'Asking Windows for permission to set up the daily task...'
    $argList = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Php', "`"$php`"", '-User', "`"$User`"")
    $p = Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $argList -Wait -PassThru
    exit $p.ExitCode
}

# Remove the always-on tasks from the first install (SM-005).
foreach ($old in @('Server', 'Tick')) {
    $t = Get-ScheduledTask -TaskPath $SmmTaskPath -TaskName $old -ErrorAction SilentlyContinue
    if ($t) {
        if ($t.State -eq 'Running') { Stop-ScheduledTask -TaskPath $SmmTaskPath -TaskName $old }
        Unregister-ScheduledTask -TaskPath $SmmTaskPath -TaskName $old -Confirm:$false
    }
}

$principal = New-ScheduledTaskPrincipal -UserId $User -LogonType S4U -RunLevel Limited
$action = New-ScheduledTaskAction -Execute $ps -WorkingDirectory $SmmRepo `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$PSScriptRoot\daily.ps1`" -Php `"$php`""
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Daily' -Action $action -Trigger (New-ScheduledTaskTrigger -Daily -At '09:00') `
    -Principal $principal -Settings $settings -Description 'SocialMedia Manager: daily jobs (account check, backup)' -Force | Out-Null

Write-Host 'Done. Open the app from the desktop shortcut; the daily jobs run at 09:00 (or when the laptop is next on).'
exit 0
