# ChatMind

ChatMind is a web-based application that imports, analyzes, summarizes, and interacts with chat conversations from different platforms using a local LLM backend.  
It works with historic conversations via TXT import and will later support live integrations (WhatsApp, Teams, Slack, etc.).

## ✨ Features

- Dashboard view with list of imported conversations
- Inline chat renaming (AJAX, no page reload)
- Conversation view with scrollable history
- "Generate AI Response" button
- Creativity slider mapped to AI temperature
- Optional formality mode
- Upload `.txt` conversation files for analysis
- Local LLM backend with OpenAI-compatible API

## 🖥 Requirements

- PHP 7.4+ (tested with PHP 8+)
- MySQL/MariaDB
- XAMPP or similar local PHP+MySQL stack
- Local LLM server (via `llama-cpp-python` or native binary)
- Node/NPM if building extra UI features

## 📦 Installation

1. Clone the repository:

```bash
git clone https://github.com/bakill3/chatmind.git
cd chatmind
```

2. Import the database schema (see `db/chatmind.sql`) into MySQL.

3. Update `config.php` with your DB credentials if needed.

4. Ensure `uploads/` is writable:

```bash
chmod 755 uploads
```

5. Start your local PHP server via XAMPP or:
```bash
php -S 127.0.0.1:8000
```

## 🤖 Running the Local Model

### Option 1 — Windows Native (fastest if you have GPU layers enabled)

```powershell
cd /d C:\xampp\htdocs\chatmind\llama
.\llama-server.exe --model ".\Meta-Llama-3.1-8B-Instruct-Q4_K_M.gguf" ^
  --alias chatmind ^
  --ctx-size 8192 ^
  --n-gpu-layers 999 ^
  --host 127.0.0.1 ^
  --port 8080
```

### Option 2 — Cross-Platform via llama-cpp-python

#### macOS/Linux
```bash
bash scripts/setup_llama.sh
```

#### Windows PowerShell
```powershell
powershell -ExecutionPolicy Bypass -File scripts\setup_llama.ps1
```

This will:
- Create a `.venv` Python virtualenv
- Install `llama-cpp-python[server]`
- Download `Phi-3-mini-4k-instruct-q4.gguf`
- Run it as an OpenAI-compatible server on `127.0.0.1:8080`

## 🔧 Config

Key configuration is in `config.php`:

```php
define('LLAMA_API_URL', 'http://127.0.0.1:8080/v1/chat/completions');
define('LLAMA_MODEL_ID', 'chatmind');
define('LLAMA_TEMP', 0.8);
```

## 📜 License

See [LICENSE](LICENSE) for details.
