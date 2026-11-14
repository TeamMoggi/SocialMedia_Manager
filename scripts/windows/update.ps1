<#
  Update and start (run by "Update and start.bat" in the App folder).
  Brings in the changes you approved on GitHub (main), updates packages and the database, restarts the
  app and opens it. It asks before changing anything and stops if the App folder has local changes.
  Log: <data folder>\logs\update.log
#>
param([switch]$Yes)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'common.ps1')

# git and composer write progress to stderr; with 'Stop', PowerShell 5.1 could treat that as a failure.
# Each step's exit code is checked instead.
$ErrorActionPreference = 'Continue'
$log = Join-Path $SmmData 'logs\update.log'
Start-Transcript -Path $log -Append | Out-Null
try {
    Set-Location $SmmRepo
    $branch = (git rev-parse --abbrev-ref HEAD).Trim()
    if ($branch -ne 'main') {
        Write-Host "This copy is on '$branch', not main. Updates only run on the App copy."
        exit 1
    }
    $changes = git status --porcelain
    if ($changes) {
        Write-Host 'Your App folder has changes, so nothing was updated:'
        $changes | ForEach-Object { Write-Host "  $_" }
        Write-Host 'Ask Claude to look at them.'
        exit 1
    }

    git fetch --quiet origin
    if ($LASTEXITCODE -ne 0) { Write-Host 'Could not reach GitHub. Check the internet connection and try again.'; exit 1 }
    $incoming = git log --oneline HEAD..origin/main
    if (-not $incoming) {
        Write-Host 'Already up to date.'
    }
    else {
        Write-Host 'Approved changes waiting to be installed:'
        $incoming | ForEach-Object { Write-Host "  $_" }
        if ($Yes) { $answer = 'Y' } else { $answer = Read-Host 'Install them now? (Y/N)' }
        if ($answer -notmatch '^[Yy]') {
            Write-Host 'Nothing was changed.'
            exit 0
        }
        & (Join-Path $PSScriptRoot 'stop.ps1')
        git pull --ff-only --quiet origin main
        if ($LASTEXITCODE -ne 0) { throw 'git pull failed' }
        composer install --no-dev --no-interaction
        if ($LASTEXITCODE -ne 0) { throw 'composer install failed' }
        php artisan migrate --force
        if ($LASTEXITCODE -ne 0) { throw 'database update failed' }
        php artisan optimize:clear
    }

    & (Join-Path $PSScriptRoot 'start.ps1') -Open
    php artisan smm:doctor
}
finally {
    Stop-Transcript | Out-Null
}
