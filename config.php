<?php
$host = 'localhost';
$db   = 'chatmind';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('UPLOADS_DIR', __DIR__ . '/uploads');

define('LLAMA_API_URL', 'http://127.0.0.1:8080/v1/chat/completions');
define('LLAMA_MODEL_ID', 'chatmind');

define('LLAMA_TEMP', 0.8);
define('LLAMA_MAX_TOKENS', 700);
define('FEWSHOT_PAIRS', 5);
define('TAIL_TURNS', 40);
define('TEMP_MIN', 0.2);
define('TEMP_MAX', 1.3);
