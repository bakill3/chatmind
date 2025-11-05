#requires -Version 5.1
Set-StrictMode -Version Latest

# ============================================
# ChatMind launcher (Docker + DB + LLM servers)
# ============================================

Set-Location -Path (Split-Path -Parent $MyInvocation.MyCommand.Definition)

function Get-DbContainerId {
    $id = (docker compose ps -q db) | ForEach-Object { $_.Trim() } | Select-Object -First 1
    if (-not $id) { throw "DB container not found. Is Docker running? Did the 'db' service start?" }
    return $id
}
function Test-DbReady {
    param([string]$DbId,[string]$DbUser,[string]$DbPass)
    docker exec $DbId mariadb --protocol=TCP -h127.0.0.1 -P3306 -u"$DbUser" -p"$DbPass" -e "SELECT 1;" 2>$null
    if ($LASTEXITCODE -eq 0) { return "tcp" }
    docker exec $DbId mariadb --protocol=SOCKET -u"$DbUser" -p"$DbPass" -e "SELECT 1;" 2>$null
    if ($LASTEXITCODE -eq 0) { return "socket" }
    return $false
}
function Has-Any-Tables {
    param([string]$DbId,[string]$DbUser,[string]$DbPass,[string]$DbName)
    $cmd = "SHOW TABLES;"
    $output = docker exec $DbId mariadb -u"$DbUser" -p"$DbPass" -e "USE $DbName; $cmd" 2>&1
    # Will return output with at least 1 row for column header if any table exists
    $lines = $output -split "`n"
    return ($lines.Length -gt 1)
}
function Import-Db {
    param([string]$DbId, [string]$Mode, [string]$DbUser, [string]$DbPass, [string]$SqlPath, [string]$DbName = "chatmind")
    if (-not (Test-Path $SqlPath)) { Write-Host "Warning: $SqlPath not found. Skipping DB import." -ForegroundColor Yellow; return }
    Write-Host "Ensuring database $DbName exists ..."
    docker exec $DbId mariadb -u"$DbUser" -p"$DbPass" -e "CREATE DATABASE IF NOT EXISTS $DbName;"
    Write-Host "Importing schema from $SqlPath into $DbName ..."
    try {
        Get-Content $SqlPath | docker exec -i $DbId mariadb -u"$DbUser" -p"$DbPass" $DbName
        Write-Host "Schema import finished (check above for any SQL errors)." -ForegroundColor Green
    } catch {
        Write-Host "Error importing schema: $_" -ForegroundColor Red
    }
}

function Test-PortOpen { param([int]$Port) try { return Test-NetConnection -ComputerName 127.0.0.1 -Port $Port -InformationLevel Quiet } catch { return $false } }
function Check-LLM-Process { param($Alias, $Port)
    $p = Get-Process | Where-Object { $_.Path -like "*llama-server.exe*" }
    if ($null -eq $p) { Write-Host "Warning: llama-server.exe is NOT running for $Alias on $Port!" -ForegroundColor Yellow }
}

Write-Host "Starting Docker Compose stack..." -ForegroundColor Green
docker compose up -d --build
Write-Host "Waiting for services to start..." -ForegroundColor Yellow
Start-Sleep -Seconds 4

$dbUser = 'admin'
$dbPass = 'adminpass'
$dbName = 'chatmind'
Write-Host "Using DB user: $dbUser" -ForegroundColor Green

Write-Host "Initializing database and tables..." -ForegroundColor Yellow
$dbId = Get-DbContainerId
$maxAttempts = 30
$mode = $false
for ($i = 1; $i -le $maxAttempts -and -not $mode; $i++) {
    Write-Host "DB check $i/$maxAttempts..." -ForegroundColor Yellow
    $mode = Test-DbReady -DbId $dbId -DbUser $dbUser -DbPass $dbPass
    if (-not $mode) { Start-Sleep -Seconds 2 }
}
if (-not $mode) {
    Write-Host "Database not ready after $maxAttempts tries. Recent logs:" -ForegroundColor Red
    docker logs $dbId --tail 120
    throw "DB failed to become ready."
} else {
    Write-Host "DB is ready via $mode." -ForegroundColor Green
    if (-not (Has-Any-Tables -DbId $dbId -DbUser $dbUser -DbPass $dbPass -DbName $dbName)) {
        Write-Host "No tables found, importing schema from .\chatmind.sql..." -ForegroundColor Cyan
        Import-Db -DbId $dbId -Mode $mode -DbUser $dbUser -DbPass $dbPass -SqlPath ".\chatmind.sql" -DbName $dbName
    } else {
        Write-Host "Database already has tables, skipping import." -ForegroundColor Yellow
    }
}

Write-Host "Checking and starting LLM servers..." -ForegroundColor Cyan
$llamaServerPath = Resolve-Path "./llama/llama-server.exe" -ErrorAction SilentlyContinue
$mainModelPath   = Resolve-Path "./llama/Meta-Llama-3.1-8B-Instruct-Q4_K_M.gguf" -ErrorAction SilentlyContinue
$miniModelPath   = Resolve-Path "./llama/Phi-3-mini-4k-instruct-q4.gguf" -ErrorAction SilentlyContinue
$freeModelPath   = Resolve-Path "./llama/WizardLM-13B-Uncensored.Q4_K_M.gguf" -ErrorAction SilentlyContinue  # optional

if ($llamaServerPath -and $mainModelPath) {
    if (-not (Test-PortOpen -Port 8080)) {
        Start-Process -FilePath $llamaServerPath.Path -ArgumentList ("--model `"{0}`" --alias chatmind --ctx-size 8192 --host 0.0.0.0 --port 8080" -f $mainModelPath.Path) -NoNewWindow
        Write-Host "Started llama-main on :8080 (alias chatmind)" -ForegroundColor Green
    } else { Write-Host "llama-main already on :8080" -ForegroundColor Yellow }
    Check-LLM-Process "chatmind" 8080
} else { Write-Host "Main LLM or model not found; skipping main." -ForegroundColor Yellow }

if ($llamaServerPath -and $miniModelPath) {
    if (-not (Test-PortOpen -Port 8081)) {
        Start-Process -FilePath $llamaServerPath.Path -ArgumentList ("--model `"{0}`" --alias chatmind-mini --ctx-size 4096 --host 0.0.0.0 --port 8081" -f $miniModelPath.Path) -NoNewWindow
        Write-Host "Started llama-mini on :8081 (alias chatmind-mini)" -ForegroundColor Green
    } else { Write-Host "llama-mini already on :8081" -ForegroundColor Yellow }
    Check-LLM-Process "chatmind-mini" 8081
} else { Write-Host "Mini LLM or model not found; skipping mini." -ForegroundColor Yellow }

# Optional third server (permissive). Only starts if the file exists.
if ($llamaServerPath -and $freeModelPath) {
    if (-not (Test-PortOpen -Port 8082)) {
        Start-Process -FilePath $llamaServerPath.Path -ArgumentList ("--model `"{0}`" --alias chatmind-free --ctx-size 8192 --host 0.0.0.0 --port 8082" -f $freeModelPath.Path) -NoNewWindow
        Write-Host "Started llama-free on :8082 (alias chatmind-free)" -ForegroundColor Green
    } else { Write-Host "llama-free already on :8082" -ForegroundColor Yellow }
    Check-LLM-Process "chatmind-free" 8082
} else {
    Write-Host "Optional free model not found; skipping chatmind-free." -ForegroundColor Yellow
}

Write-Host "Opening browser to http://127.0.0.1:8000/" -ForegroundColor Cyan
Start-Process "http://127.0.0.1:8000/"
Write-Host "ChatMind is now running!" -ForegroundColor Green
