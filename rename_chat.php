<?php
require 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauth']); exit;
}

$upload_id = (int)($_POST['upload_id'] ?? 0);
$title     = trim($_POST['title'] ?? '');

if ($upload_id <= 0) { echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }
if ($title === '')   { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
if (mb_strlen($title) > 255) { echo json_encode(['ok'=>false,'error'=>'too_long']); exit; }

// verify ownership
$stmt = $pdo->prepare("SELECT id FROM uploads WHERE id=? AND user_id=?");
$stmt->execute([$upload_id, $_SESSION['user_id']]);
if (!$stmt->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

// update
$stmt = $pdo->prepare("UPDATE uploads SET title = ? WHERE id = ?");
$stmt->execute([$title, $upload_id]);

echo json_encode(['ok'=>true, 'title'=>$title]);
