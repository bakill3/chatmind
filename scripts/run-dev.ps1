# Run-dev helper for Windows PowerShell
param(
  [switch]$Detach
)

Set-Location -Path (Join-Path $PSScriptRoot "..")
Write-Host "Starting services with docker compose..."
if ($Detach) {
  docker compose up -d --build
} else {
  docker compose up --build
}

# Wait for web to be healthy (basic)
$tries = 0
while ($tries -lt 40) {
  try {
    $r = Invoke-WebRequest -UseBasicParsing -Uri http://127.0.0.1:8000 -TimeoutSec 2 -ErrorAction Stop
    if ($r.StatusCode -eq 200) { Write-Host "Web is up: http://127.0.0.1:8000"; Start-Process "http://127.0.0.1:8000"; break }
  } catch {
    Start-Sleep -Seconds 1
    $tries++
  }
}
if ($tries -ge 40) { Write-Host "Timed out waiting for web. Check 'docker compose ps' and logs." }
