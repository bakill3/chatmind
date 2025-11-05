$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

# Find Python
$Py = $env:PY_BIN
if (-not $Py) { $Py = "python" }
$pythonFound = $false
try {
  & $Py --version | Out-Null
  $pythonFound = $true
} catch { $pythonFound = $false }
if (-not $pythonFound) {
  Write-Host "Python not found. Install Python 3.10+ and re-run." -ForegroundColor Red
  exit 1
}

# venv
if (-not (Test-Path ".venv")) {
  & $Py -m venv .venv
}
$Activate = ".\.venv\Scripts\Activate.ps1"
. $Activate

pip install --upgrade pip
pip install "llama-cpp-python[server]"

# model
$LlamaDir = "llama\models"
if (-not (Test-Path $LlamaDir)) { New-Item -ItemType Directory -Path $LlamaDir | Out-Null }
$ModelPath = Join-Path $LlamaDir "Phi-3-mini-4k-instruct-q4.gguf"
if (-not (Test-Path $ModelPath)) {
  $Url = "https://huggingface.co/microsoft/phi-3-mini-4k-instruct-gguf/resolve/main/Phi-3-mini-4k-instruct-q4.gguf?download=true"
  Invoke-WebRequest -Uri $Url -OutFile $ModelPath
}

$HostAddr = $env:HOST; if (-not $HostAddr) { $HostAddr = "127.0.0.1" }
$Port     = $env:PORT; if (-not $Port)     { $Port = "8080" }

# Run server (OpenAI-compatible)
python -m llama_cpp.server `
  --host $HostAddr `
  --port $Port `
  --model $ModelPath `
  --chat_format phi3