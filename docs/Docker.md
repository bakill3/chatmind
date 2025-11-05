# Dockerizing ChatMind — quickstart

This repository includes a minimal Docker Compose setup to run the web app, MariaDB, and (optionally) one or two Llama server instances.

Prerequisites
- Docker Desktop (Windows) or Docker Engine + docker-compose
- If you plan to run GPU-accelerated LLMs, install the appropriate NVIDIA drivers and the nvidia-container-toolkit; additional configuration is required.

What the compose file provides
- `web` — PHP + Apache container serving the app on host port 8000
- `db` — MariaDB for the app
- `llama-main` — placeholder container that will run a `llama-server` binary and the large model (8B). You must provide a compatible linux `llama-server` binary and GGUF model files in `./llama/`.
- `llama-mini` — optional smaller LLM for fast responses; also expects binaries/models in `./llama/`.

Important: models and binaries
- Due to licensing and large file sizes we do not include GGUF models in the repo. Place your model files in the `llama/` directory at the project root. Example:

  ./llama/Meta-Llama-3.1-8B-Instruct-Q4_K_M.gguf
  ./llama/Phi-3-mini-4k-instruct-q4.gguf

- Provide a Linux `llama-server` executable (compiled for your platform) at `./llama/llama-server` (make it executable). The `llama-main` and `llama-mini` services in docker-compose will attempt to run `/llama/llama-server`.

Starting the stack (recommended)
- On Windows (PowerShell):

  .\scripts\run-dev.ps1 -Detach

- On Linux / macOS:

  ./scripts/run-dev.sh

This will build the PHP image and bring up containers. The web app will be available at http://127.0.0.1:8000.

Configuration
- The web container is configured via environment variables (see `docker-compose.yml`):
  - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
  - `LLAMA_API_URL` – by default points to `http://llama-main:8080/v1/chat/completions`
  - `LLAMA_MODEL_ID`, `QUICK_MODEL_ALIAS`

Notes on GPU / production
- For GPU usage, use an LLM server image and run with the `--gpus` flag or the nvidia runtime. The current compose file uses a simple `ubuntu` image and expects a prebuilt `llama-server` binary.
- In production you should not expose LLM servers publicly — keep them on an internal network and protect the web frontend.

Troubleshooting
- If the Llama container prints "Place linux llama-server binary at ./llama/llama-server" then you must place the binary in `./llama` or switch the service to use a community image that contains a runnable server.
- Check logs with:
  - `docker compose logs web`
  - `docker compose logs llama-main`

Further improvements (next steps)
- Use an official / community llama-server image in the compose file to avoid requiring a local binary.
- Add a model-downloader helper that guides users through licensed downloads and places models in the right folder.
- Support GPU-enabled images and add environment variable toggles for CUDA.

