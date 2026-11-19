<#
  Wake-up trial (SM-008): finds out what this laptop allows WITHOUT an administrator prompt, so that
  automatic posting (part 3) can wake the laptop at a post's time. Nothing permanent changes:
    1. reads which sleep states the laptop has and the "Allow wake timers" setting (plugged in / battery);
    2. tries to create a one-time task that wakes the laptop, first in the \SocialMediaManager\ folder,
       then in the main folder;
    3. tries to rewrite the Daily task with its own, unchanged start time (allowed or denied);
    4. waits for the trial task to run; with -Sleep it first puts the laptop to sleep, so the task has
       to wake it.
  The trial task and its file are removed at the end. Result: <data folder>\run\wake-trial.json
  Run it from a normal (not administrator) window:
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\windows\wake-trial.ps1 [-Sleep] [-Minutes 3]
#>
param([switch]$Sleep, [int]$Minutes = 2)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

$runDir = Join-Path $SmmData 'run'
New-Item -ItemType Directory -Force -Path $runDir | Out-Null
$ranFile = Join-Path $runDir 'wake-trial-ran.txt'
$resultFile = Join-Path $runDir 'wake-trial.json'
Remove-Item $ranFile -ErrorAction SilentlyContinue

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$isAdmin = ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
$result = [ordered]@{
    at = (Get-Date).ToString('s')
    user = $identity.Name
    elevated = $isAdmin
    sleepStates = @()
    wakeTimersPluggedIn = $null
    wakeTimersOnBattery = $null
    taskInAppFolder = $null
    taskInMainFolder = 'not tried'
    rewriteDaily = $null
    runTest = $null
}
if ($isAdmin) {
    Write-Host 'Note: this window runs as administrator, so the results do not show what the app may do on its own. Run it from a normal window.'
}

# 1. Sleep states and the wake-timer setting (0 = off, 1 = on, 2 = important wake timers only).
$available = @()
foreach ($line in (& powercfg /a)) {
    if ($line -match 'not available') { break }
    if ($line -match '^\s+(Standby|Hibernate|Hybrid|Fast)') { $available += $line.Trim() }
}
$result.sleepStates = $available
$timer = (& powercfg /query SCHEME_CURRENT SUB_SLEEP RTCWAKE) -join "`n"
$names = @{ 0 = 'off'; 1 = 'on'; 2 = 'important only' }
if ($timer -match 'AC Power Setting Index:\s*0x([0-9a-fA-F]+)') { $result.wakeTimersPluggedIn = $names[[Convert]::ToInt32($Matches[1], 16)] }
if ($timer -match 'DC Power Setting Index:\s*0x([0-9a-fA-F]+)') { $result.wakeTimersOnBattery = $names[[Convert]::ToInt32($Matches[1], 16)] }

# 2. A one-time task that wakes the laptop and writes the time it ran.
$ps = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$command = "Set-Content -Path '{0}' -Value ([DateTime]::Now.ToString('s'))" -f $ranFile
$action = New-ScheduledTaskAction -Execute $ps -Argument "-NoProfile -WindowStyle Hidden -Command `"$command`""
$runAt = (Get-Date).AddMinutes($Minutes)
$trigger = New-ScheduledTaskTrigger -Once -At $runAt
$settings = New-ScheduledTaskSettingsSet -WakeToRun -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
$principal = New-ScheduledTaskPrincipal -UserId $identity.Name -LogonType Interactive -RunLevel Limited
$made = @()

function Register-Trial([string]$TaskPath, [string]$TaskName) {
    try {
        Register-ScheduledTask -TaskPath $TaskPath -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
            -Principal $principal -Description 'SocialMedia Manager wake-up trial (removed automatically)' -Force | Out-Null
        return 'allowed'
    }
    catch { return 'denied: ' + $_.Exception.Message.Trim() }
}

try {
    $result.taskInAppFolder = Register-Trial $SmmTaskPath 'WakeTrial'
    if ($result.taskInAppFolder -eq 'allowed') { $made += @($SmmTaskPath, 'WakeTrial') }
    else {
        $result.taskInMainFolder = Register-Trial '\' 'SocialMediaManager-WakeTrial'
        if ($result.taskInMainFolder -eq 'allowed') { $made += @('\', 'SocialMediaManager-WakeTrial') }
    }

    # 3. Rewrite the Daily task with its own, unchanged start time.
    $daily = Get-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Daily' -ErrorAction SilentlyContinue
    if (-not $daily) { $result.rewriteDaily = 'no Daily task on this laptop' }
    else {
        try {
            Set-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Daily' -Trigger $daily.Triggers -ErrorAction Stop | Out-Null
            $result.rewriteDaily = 'allowed'
        }
        catch { $result.rewriteDaily = 'denied: ' + $_.Exception.Message.Trim() }
    }

    # 4. Does the task run (and, with -Sleep, wake the laptop)?
    if ($made.Count -eq 0) { $result.runTest = 'not run: no trial task could be made' }
    else {
        if ($Sleep) {
            Write-Host ("The laptop goes to sleep in 10 seconds and should wake itself at {0}. Leave it alone until then." -f $runAt.ToString('HH:mm'))
            Start-Sleep -Seconds 10
            Add-Type -AssemblyName System.Windows.Forms
            [System.Windows.Forms.Application]::SetSuspendState([System.Windows.Forms.PowerState]::Suspend, $false, $false) | Out-Null
        }
        else {
            Write-Host ("Waiting for the trial task to run at {0} (laptop awake)..." -f $runAt.ToString('HH:mm'))
        }
        $deadline = $runAt.AddMinutes(3)
        while ((Get-Date) -lt $deadline -and -not (Test-Path $ranFile)) { Start-Sleep -Seconds 10 }
        $mode = if ($Sleep) { 'from sleep' } else { 'while awake' }
        if (Test-Path $ranFile) {
            $ran = ([System.IO.File]::ReadAllText($ranFile)).Trim()
            $result.runTest = "ran $mode at $ran (planned $($runAt.ToString('s')))"
        }
        else { $result.runTest = "did not run $mode by $($deadline.ToString('s'))" }
    }
}
finally {
    if ($made.Count -eq 2) {
        Unregister-ScheduledTask -TaskPath $made[0] -TaskName $made[1] -Confirm:$false -ErrorAction SilentlyContinue
    }
    Remove-Item $ranFile -ErrorAction SilentlyContinue
    [System.IO.File]::WriteAllText($resultFile, ($result | ConvertTo-Json -Depth 3))
}

Write-Host ''
Write-Host 'Wake-up trial result:'
$result.GetEnumerator() | ForEach-Object { Write-Host ("  {0}: {1}" -f $_.Key, (@($_.Value) -join '; ')) }
Write-Host "Saved to $resultFile"
