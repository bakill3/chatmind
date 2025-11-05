<?php
$host = getenv('DB_HOST') ?: 'db';
$db   = getenv('DB_NAME') ?: 'chatmind';
$user = getenv('DB_USER') ?: 'admin';
$pass = getenv('DB_PASS') ?: 'adminpass';

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $db);
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

try {
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) { /* ignore */ }

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('UPLOADS_DIR', __DIR__ . '/uploads');

/**
 * LLM endpoints / aliases
 * - main on :8080  (alias: chatmind)
 * - mini on :8081  (alias: chatmind-mini)
 * - optional “free/permissive” on :8082 (alias: chatmind-free)
 */
define('LLAMA_API_URL',     getenv('LLAMA_API_URL')     ?: 'http://host.docker.internal:8080/v1/chat/completions');
define('LLAMA_API_URL_MINI',getenv('LLAMA_API_URL_MINI')?: 'http://host.docker.internal:8081/v1/chat/completions');
define('LLAMA_API_URL_FREE',getenv('LLAMA_API_URL_FREE')?: 'http://host.docker.internal:8082/v1/chat/completions');

define('LLAMA_MODEL_ID',   getenv('LLAMA_MODEL_ID')   ?: 'chatmind');
define('QUICK_MODEL_ALIAS',getenv('QUICK_MODEL_ALIAS')?: 'chatmind-mini');
define('FREE_MODEL_ALIAS', getenv('FREE_MODEL_ALIAS') ?: 'chatmind-free');

define('LLAMA_TEMP', 0.8);
define('LLAMA_MAX_TOKENS', 700);
define('FEWSHOT_PAIRS', 5);
define('TAIL_TURNS', 40);
define('TEMP_MIN', 0.2);
define('TEMP_MAX', 1.3);
define('MAX_TOKENS_DEFAULT', 256);

// Retriever & exemplar tuning
define('RETRIEVER_RECENT_TURNS', 200);
define('RETRIEVER_TOPK', 6);
define('EXEMPLARS_TOPK', 6);
define('EXEMPLARS_MAX', 12);

// Profile storage
define('PROFILE_JSON_DIR', __DIR__ . '/uploads/profile_json');

// Books
define('BOOKS_DEFAULT_WEIGHT', 0.7);

// Language norm
define('DEFAULT_TARGET_LANG', 'pt-PT');

// Performance
define('SUGGESTIONS_MAX_TOKENS', 180);
define('TAIL_TURNS_QUICK', 24);
define('QUICK_MODE_DEFAULT', true);

/**
 * Normalize text to UTF-8 and strip LTR/RLM control chars.
 */
function to_utf8(string $s): string {
    if ($s === '') return '';
    $enc = mb_detect_encoding($s, ['UTF-8','ISO-8859-1','Windows-1252'], true);
    if ($enc && $enc !== 'UTF-8') {
        $s = mb_convert_encoding($s, 'UTF-8', $enc);
    } else {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }
    $s = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $s);
    return $s;
}

/**
 * Select API URL by alias (so warm/override can hit the mini/free servers).
 */
function api_url_for_model(string $alias): string {
    $alias = trim($alias);
    if ($alias === QUICK_MODEL_ALIAS) return LLAMA_API_URL_MINI;
    if ($alias === FREE_MODEL_ALIAS)  return LLAMA_API_URL_FREE;
    return LLAMA_API_URL;
}
