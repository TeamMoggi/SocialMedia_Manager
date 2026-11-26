<#
  Makes the one-time wake-up tasks for automatic posting. Each time in -Times (ISO 8601, comma separated) gets a
  task \SocialMediaManager\Publish-<yyyyMMddHHmm UTC> that wakes the laptop and runs publish.ps1; tasks for times
  that are no longer needed (and past ones) are removed. No administrator prompt: an Interactive task for the
  signed-in user in \SocialMediaManager\ is allowed (wake-up trial, 21 Nov 2026).
    wake-at.ps1 -Times "2026-12-04T05:45:00+00:00,2026-12-04T06:00:00+00:00" [-Php C:\path\php.exe]
    wake-at.ps1 -Times ""            (removes all of them)
#>
param([string]$Times = '', [string]$Php = '', [switch]$AllowDev)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

if (-not $SmmIsApp -and -not $AllowDev) { Write-Host 'Wake-up tasks are made only from the App copy.'; exit 0 }
$php = Get-SmmPhp $Php
$ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$prefix = 'Publish-'

$wanted = @{}
foreach ($t in ($Times -split ',' | Where-Object { $_.Trim() })) {
    $utc = [DateTimeOffset]::Parse($t.Trim()).UtcDateTime
    if ($utc -gt [DateTime]::UtcNow) { $wanted[$prefix + $utc.ToString('yyyyMMddHHmm')] = $utc }
}

$existing = @(Get-ScheduledTask -TaskPath $SmmTaskPath -ErrorAction SilentlyContinue | Where-Object { $_.TaskName -like "$prefix*" })
foreach ($task in $existing) {
    if (-not $wanted.ContainsKey($task.TaskName)) {
        Unregister-ScheduledTask -TaskPath $SmmTaskPath -TaskName $task.TaskName -Confirm:$false -ErrorAction SilentlyContinue
        Write-Host "Removed $($task.TaskName)"
    }
}

$settings = New-ScheduledTaskSettingsSet -WakeToRun -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId $identity.Name -LogonType Interactive -RunLevel Limited
$action = New-ScheduledTaskAction -Execute $ps -WorkingDirectory $SmmRepo `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$PSScriptRoot\publish.ps1`" -Php `"$php`""
foreach ($name in $wanted.Keys) {
    if ($existing | Where-Object { $_.TaskName -eq $name }) { continue }
    $local = $wanted[$name].ToLocalTime()
    Register-ScheduledTask -TaskPath $SmmTaskPath -TaskName $name -Action $action -Trigger (New-ScheduledTaskTrigger -Once -At $local) `
        -Settings $settings -Principal $principal -Description 'SocialMedia Manager: wake up and publish due posts' -Force | Out-Null
    Write-Host "Made $name for $($local.ToString('s'))"
}
exit 0
