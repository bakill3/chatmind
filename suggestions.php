<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauth']); exit; }

$user_id   = (int)$_SESSION['user_id'];
$upload_id = (int)($_POST['upload_id'] ?? 0);
$received  = trim((string)($_POST['received'] ?? ''));
$limit     = min(6, max(1, (int)($_POST['limit'] ?? 4)));

if ($upload_id <= 0 || $received === '') { echo json_encode(['ok'=>false,'error'=>'bad_input']); exit; }

function tokenize($s){
  $s = mb_strtolower($s);
  $s = preg_replace('/https?:\/\/\S+/u',' ', $s);
  $s = preg_replace('/[^a-z0-9áéíóúâêîôûãõç ]/iu',' ', $s);
  $parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
  return array_values(array_unique(array_filter($parts, fn($w)=>mb_strlen($w)>2)));
}

$rx = tokenize($received);
if (!$rx) { echo json_encode(['ok'=>true,'suggestions'=>[]]); exit; }

// gather other uploads of the same user
$stmt = $pdo->prepare("SELECT id, participants_json FROM uploads WHERE user_id=? AND id<>?");
$stmt->execute([$user_id, $upload_id]);
$others = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Collect participants names for redaction
$allNames = [];
foreach ($others as $o) {
  if (!empty($o['participants_json'])) {
    $p = json_decode($o['participants_json'], true);
    if (is_array($p)) foreach ($p as $n) $allNames[$n] = true;
  }
}

$q = $pdo->prepare("SELECT id, upload_id, approved_reply, tone_tags, contact_name, source_message, created_at
                    FROM approved_replies
                    WHERE user_id=? AND upload_id<>?
                    ORDER BY created_at DESC
                    LIMIT 400");
$q->execute([$user_id, $upload_id]);
$pool = $q->fetchAll(PDO::FETCH_ASSOC);

// score by keyword overlap (cheap & cheerful)
$scores = [];
foreach ($pool as $row) {
  $tokens = tokenize(($row['source_message'] ?: '') . ' ' . $row['approved_reply']);
  if (!$tokens) continue;
  $overlap = count(array_intersect($rx, $tokens));
  if ($overlap <= 0) continue;
  // simple timestamp boost (recency)
  $tsBoost = strtotime($row['created_at']) / 1e9;
  $score = $overlap + $tsBoost;
  $scores[] = [$score, $row];
}
usort($scores, fn($a,$b)=> $a[0]==$b[0] ? 0 : ($a[0]<$b[0] ? 1 : -1)); // desc

// redact participant names (very light)
$names = array_keys($allNames);
$suggests = [];
foreach (array_slice($scores, 0, $limit) as [$sc, $row]) {
  $text = $row['approved_reply'];
  foreach ($names as $n) {
    if (!$n) continue;
    $pat = '/' . preg_quote($n, '/') . '/iu';
    $text = preg_replace($pat, '{{NAME}}', $text);
  }
  $suggests[] = [
    'approved_id' => (int)$row['id'],
    'upload_id'   => (int)$row['upload_id'],
    'reply'       => $text,
    'tone_tags'   => $row['tone_tags'],
    'contact'     => $row['contact_name'],
  ];
}

echo json_encode(['ok'=>true, 'suggestions'=>$suggests]);
