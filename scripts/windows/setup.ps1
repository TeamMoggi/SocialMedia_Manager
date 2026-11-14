<#
  SocialMedia Manager - first-time setup of one copy (App or Dev).

  Creates the data folder outside the code, points this copy at it (.data-dir), writes the settings
  file (.env) there, creates the database, and runs the self-check. Safe to run again: it never
  replaces an existing app key (that would make saved account access unreadable).

  Examples (Windows PowerShell):
    .\scripts\windows\setup.ps1 -DataDir D:\Pravin\Moggi\SocialMedia_Manager\Data -PublishMode live -BackupDir D:\Pravin\Moggi\SocialMedia_Manager\Backups
    .\scripts\windows\setup.ps1 -DataDir D:\Pravin\Moggi\SocialMedia_Manager\DevData -PublishMode dry-run -Port 8091
#>
param(
    [Parameter(Mandatory = $true)][string]$DataDir,
    [ValidateSet('live', 'dry-run')][string]$PublishMode = 'dry-run',
    [string]$BackupDir = '',
    [int]$Port = 8090
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

if (-not [System.IO.Path]::IsPathRooted($DataDir)) {
    throw "DataDir must be a full path, for example D:\Pravin\Moggi\SocialMedia_Manager\Data"
}
if ($BackupDir -eq '') { $BackupDir = Join-Path $DataDir 'backups' }
$noBom = New-Object System.Text.UTF8Encoding($false)

# 1. Folders
$subFolders = @('storage\app', 'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'media', 'run', 'logs')
foreach ($sub in $subFolders) { New-Item -ItemType Directory -Force -Path (Join-Path $DataDir $sub) | Out-Null }
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

# 2. Point this copy at its data folder
[System.IO.File]::WriteAllText((Join-Path $repo '.data-dir'), $DataDir, $noBom)

# 3. Settings file in the data folder
$envFile = Join-Path $DataDir '.env'
if (-not (Test-Path $envFile)) { Copy-Item (Join-Path $repo '.env.example') $envFile }
$db = Join-Path $DataDir 'database.sqlite'
if (-not (Test-Path $db)) { New-Item -ItemType File -Path $db | Out-Null }

function Set-EnvValue([string]$File, [string]$Key, [string]$Value) {
    $lines = [System.IO.File]::ReadAllLines($File)
    $found = $false
    $out = New-Object System.Collections.Generic.List[string]
    foreach ($line in $lines) {
        if ($line -match ('^' + [regex]::Escape($Key) + '=')) { $out.Add("$Key=$Value"); $found = $true }
        else { $out.Add($line) }
    }
    if (-not $found) { $out.Add("$Key=$Value") }
    [System.IO.File]::WriteAllLines($File, $out.ToArray(), $noBom)
}

# Single quotes: the .env reader keeps backslashes in Windows paths as they are.
Set-EnvValue $envFile 'SMM_PUBLISH_MODE' $PublishMode
Set-EnvValue $envFile 'SMM_PORT' "$Port"
Set-EnvValue $envFile 'SMM_BACKUP_DIR' "'$BackupDir'"
Set-EnvValue $envFile 'DB_DATABASE' "'$db'"
Set-EnvValue $envFile 'APP_URL' "http://127.0.0.1:$Port"
if ($PublishMode -eq 'live') { Set-EnvValue $envFile 'APP_DEBUG' 'false' } else { Set-EnvValue $envFile 'APP_DEBUG' 'true' }

# 4. App key (only when empty), database tables, self-check
$php = (Get-Command php -ErrorAction Stop).Source
Push-Location $repo
try {
    $keyLine = Select-String -Path $envFile -Pattern '^APP_KEY=(.*)$' | Select-Object -First 1
    if (-not $keyLine -or $keyLine.Matches[0].Groups[1].Value.Trim() -eq '') {
        & $php artisan key:generate --force
        if ($LASTEXITCODE -ne 0) { throw 'key:generate failed' }
    }
    else { Write-Host 'App key already set; keeping it.' }
    & $php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'migrate failed' }
    & $php artisan db:seed --force
    if ($LASTEXITCODE -ne 0) { throw 'db:seed failed' }
    & $php artisan smm:doctor
    exit $LASTEXITCODE
}
finally { Pop-Location }
