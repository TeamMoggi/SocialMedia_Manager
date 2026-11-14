# Shared by the SocialMedia Manager scripts: dot-source it ( . (Join-Path $PSScriptRoot 'common.ps1') ).
# Works in Windows PowerShell 5.1.

$SmmRepo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$SmmIsApp = ((Split-Path $SmmRepo -Leaf) -eq 'App')
$SmmTaskPath = '\SocialMediaManager\'

$dataDirFile = Join-Path $SmmRepo '.data-dir'
if (-not (Test-Path $dataDirFile)) { throw "This copy is not set up yet: run scripts\windows\setup.ps1 first." }
$SmmData = ([System.IO.File]::ReadAllText($dataDirFile)).Trim([char]0xFEFF).Trim()
$SmmEnv = Join-Path $SmmData '.env'

$SmmPort = 8090
$portLine = Select-String -Path $SmmEnv -Pattern '^SMM_PORT=(\d+)' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($portLine) { $SmmPort = [int]$portLine.Matches[0].Groups[1].Value }
$SmmUrl = "http://127.0.0.1:$SmmPort"

function Get-SmmPhp([string]$Given) {
    if ($Given -and (Test-Path $Given)) { return $Given }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw 'PHP was not found. Install it, or pass -Php with the full path to php.exe.'
}

# True when our app answers on its port (Laravel's /up health address).
function Test-SmmApp {
    try {
        $r = Invoke-WebRequest -Uri "$SmmUrl/up" -UseBasicParsing -TimeoutSec 3
        return ($r.StatusCode -eq 200)
    }
    catch { return $false }
}

# True when anything at all listens on the port.
function Test-SmmPortBusy {
    $client = New-Object System.Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1', $SmmPort); return $true }
    catch { return $false }
    finally { $client.Close() }
}

function Get-SmmServerTask {
    if (-not $SmmIsApp) { return $null }
    return Get-ScheduledTask -TaskPath $SmmTaskPath -TaskName 'Server' -ErrorAction SilentlyContinue
}
