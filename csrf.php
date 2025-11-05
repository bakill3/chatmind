<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_field(): string {
  return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8').'">';
}

function require_valid_csrf(bool $as_json = false): void {
  $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $ok = is_string($sent) && hash_equals(csrf_token(), $sent);
  if ($ok) return;

  if ($as_json) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(419);
    echo json_encode(['ok'=>false,'error'=>'csrf_failed']);
  } else {
    http_response_code(419);
    echo "CSRF token inválido.";
  }
  exit;
}