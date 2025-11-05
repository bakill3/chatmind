<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Método inválido']); exit; }
if (!isset($_SESSION['user_id']))          { http_response_code(403); echo json_encode(['error'=>'Sem sessão']); exit; }

function body($k,$d=null){ return $_POST[$k] ?? $d; }
function clean($s){ return trim((string)$s); }

$user_id       = (int)$_SESSION['user_id'];
$upload_id     = (int)body('upload_id', 0) ?: null;
$contact_name  = clean(body('contact_name', '')) ?: null;
$received_text = clean(body('received', ''));
$reply_text    = clean(body('reply', ''));
$model_used    = clean(body('model', ''));
$temp_used     = is_numeric(body('temp', null)) ? (float)body('temp', null) : null;
$pair_hash     = clean(body('pair_hash', ''));
$dir           = clean(body('dir', '')); // 'up' | 'down'
$books         = body('books', []); if (!is_array($books)) $books = [];

if ($received_text === '' || $reply_text === '' || ($dir !== 'up' && $dir !== 'down')) {
  echo json_encode(['error'=>'Dados incompletos']); exit;
}

$pdo->query("SET NAMES utf8mb4");

if ($pair_hash === '') {
  $pair_hash = sha1($user_id.'|'.$received_text.'|'.$reply_text);
}

try {
  // Create candidate (is_approved=0) if not exists, else update vote counts
  $stmt = $pdo->prepare("SELECT id, score_up, score_down FROM approved_messages WHERE user_id = ? AND pair_hash = ?");
  $stmt->execute([$user_id, $pair_hash]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $stmt = $pdo->prepare("
      INSERT INTO approved_messages
        (user_id, upload_id, contact_name, received_text, approved_reply, model_used, temp_used, books_json, is_approved, score_up, score_down, pair_hash)
      VALUES
        (:user_id, :upload_id, :contact_name, :rx, :ax, :model_used, :temp_used, :books_json, 0, :up, :down, :pair_hash)
    ");
    $stmt->execute([
      ':user_id'      => $user_id,
      ':upload_id'    => $upload_id,
      ':contact_name' => $contact_name,
      ':rx'           => $received_text,
      ':ax'           => $reply_text,
      ':model_used'   => $model_used ?: null,
      ':temp_used'    => $temp_used,
      ':books_json'   => $books ? json_encode(array_values($books), JSON_UNESCAPED_UNICODE) : null,
      ':up'           => ($dir === 'up' ? 1 : 0),
      ':down'         => ($dir === 'down' ? 1 : 0),
      ':pair_hash'    => $pair_hash,
    ]);
    echo json_encode(['ok'=>true, 'pair_hash'=>$pair_hash, 'new'=>true]);
    exit;
  }

  $id = (int)$row['id'];
  $stmt = $pdo->prepare("
    UPDATE approved_messages
    SET score_up = score_up + :inc_up,
        score_down = score_down + :inc_down,
        upload_id = COALESCE(:upload_id, upload_id),
        contact_name = COALESCE(:contact_name, contact_name),
        model_used = NULLIF(:model_used,''),
        temp_used = :temp_used,
        books_json = COALESCE(:books_json, books_json)
    WHERE id = :id AND user_id = :user_id
  ");
  $stmt->execute([
    ':inc_up'      => ($dir === 'up' ? 1 : 0),
    ':inc_down'    => ($dir === 'down' ? 1 : 0),
    ':upload_id'   => $upload_id,
    ':contact_name'=> $contact_name,
    ':model_used'  => $model_used ?: null,
    ':temp_used'   => $temp_used,
    ':books_json'  => $books ? json_encode(array_values($books), JSON_UNESCAPED_UNICODE) : null,
    ':id'          => $id,
    ':user_id'     => $user_id
  ]);
  echo json_encode(['ok'=>true, 'pair_hash'=>$pair_hash, 'new'=>false]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'DB error: '.$e->getMessage()]);
}

// update lightweight profile stats asynchronously (best-effort)
try {
  $hook = __DIR__ . '/assets/profile_approval_hook.php';
  if (is_file($hook)) { include_once $hook; update_profile_from_approvals($pdo, $user_id, $upload_id, $contact_name); }
} catch (Throwable $e) {
  // ignore
}
