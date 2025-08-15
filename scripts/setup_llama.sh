#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PY_BIN="${PY_BIN:-python3}"
if ! command -v "$PY_BIN" >/dev/null 2>&1; then
  echo "python3 not found. Install Python 3.10+ and re-run."
  exit 1
fi

if [ ! -d ".venv" ]; then
  "$PY_BIN" -m venv .venv
fi

. .venv/bin/activate
pip install --upgrade pip
pip install "llama-cpp-python[server]"

mkdir -p llama/models
MODEL_PATH="llama/models/Phi-3-mini-4k-instruct-q4.gguf"
if [ ! -f "$MODEL_PATH" ]; then
  curl -L -o "$MODEL_PATH" "https://huggingface.co/microsoft/phi-3-mini-4k-instruct-gguf/resolve/main/Phi-3-mini-4k-instruct-q4.gguf?download=true"
fi

HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"

exec .venv/bin/python -m llama_cpp.server \
  --host "$HOST" \
  --port "$PORT" \
  --model "$MODEL_PATH" \
  --chat_format phi3