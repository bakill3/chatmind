<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'unauth']); exit; }

/** ---- helpers ---- */
if (!function_exists('to_utf8')) {
  function to_utf8($mixed) {
    if (is_array($mixed)) {
      $ret = [];
      foreach ($mixed as $k=>$v) $ret[to_utf8($k)] = to_utf8($v);
      return $ret;
    }
    if (is_null($mixed)) return '';
    if (!is_string($mixed)) $mixed = (string)$mixed;
    if (!mb_detect_encoding($mixed, 'UTF-8', true)) {
      $tmp = @mb_convert_encoding($mixed, 'UTF-8', 'auto');
      if ($tmp !== false) $mixed = $tmp;
    }
    return $mixed;
  }
}

function normalize_to_utf8(string $raw): string {
  if ($raw === '') return '';
  if (isset($raw[1])) {
    $bom2 = $raw[0] . $raw[1];
    if ($bom2 === "\xFF\xFE") { return function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : @iconv('UTF-16LE','UTF-8//IGNORE',$raw); }
    if ($bom2 === "\xFE\xFF") { return function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : @iconv('UTF-16BE','UTF-8//IGNORE',$raw); }
  }
  if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
    $enc = mb_detect_encoding($raw, ['UTF-8','ISO-8859-1','Windows-1252'], true);
    if ($enc && $enc !== 'UTF-8') return mb_convert_encoding($raw, 'UTF-8', $enc);
  }
  return $raw;
}

function utf8ize($mixed) {
  if (is_array($mixed)) {
    $ret = [];
    foreach ($mixed as $k=>$v) { $ret[utf8ize($k)] = utf8ize($v); }
    return $ret;
  }
  if (is_string($mixed)) {
    $s = $mixed;
    if (!mb_detect_encoding($s, 'UTF-8', true)) {
      $tmp = @mb_convert_encoding($s, 'UTF-8', 'auto');
      if ($tmp !== false) $s = $tmp;
    }
    return $s;
  }
  return $mixed;
}

/* ---------- WhatsApp parsing ---------- */
function start_re(): string {
  return '/^\s*\[?(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\]?[\s\-–]*([^:]+):\s*(.*)$/u';
}
function parse_to_messages(string $txt): array {
  $txt = normalize_to_utf8($txt);
  $txt = str_replace(["\r\n","\r"], "\n", $txt);
  $lines = explode("\n", $txt);
  $re = start_re();

  $out = [];
  $cur = null;
  foreach ($lines as $raw) {
    $line = to_utf8($raw);
    if ($line === '') continue;
    if (preg_match($re, $line, $m)) {
      if ($cur && ($cur['message'] ?? '') !== '') {
        $cur['message'] = preg_replace('/\s+/u',' ', trim($cur['message']));
        $out[] = $cur;
      }
      $day = (int)$m[1]; $mon = (int)$m[2]; $yr = (int)$m[3];
      if ($yr < 100) { $yr += ($yr > 30 ? 1900 : 2000); }
      $hour = (int)$m[4]; $min = (int)$m[5]; $sec = isset($m[6]) ? (int)$m[6] : 0;
      $sender = trim(to_utf8($m[7] ?? ''));
      $msg = trim(to_utf8($m[8] ?? ''));
      $time = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $yr, $mon, $day, $hour, $min, $sec);
      $cur = ['sender'=>$sender, 'message'=>$msg, 'time'=>$time];
    } else {
      if ($cur) {
        $cur['message'] .= ' ' . to_utf8(trim($line));
      }
    }
  }
  if ($cur && ($cur['message'] ?? '') !== '') {
    $cur['message'] = preg_replace('/\s+/u',' ', trim($cur['message']));
    $out[] = $cur;
  }
  // quick clean of attachment/system markers
  $needles = [
    'imagem não revelada','image omitted',
    'vídeo não revelado','video omitted',
    'ficheiro de áudio não revelado','audio omitted',
    'sticker não revelado','gif omitido','gif omitted',
    'esta mensagem foi editada','mensagem apagada',
    'documento não revelado','chamada perdida',
  ];
  $out2=[];
  foreach ($out as $m) {
    $t = mb_strtolower($m['message'] ?? '');
    $skip = false; foreach ($needles as $n) { if (mb_strpos($t,$n)!==false){$skip=true;break;} }
    if (!$skip) $out2[] = $m;
  }
  return $out2;
}

function build_sample(array $messages, int $max = 140): string {
  $n = count($messages);
  $start = max(0, $n - $max);
  $buf = [];
  for ($i=$start; $i<$n; $i++) {
    $s = trim($messages[$i]['sender'] ?? '');
    $m = trim($messages[$i]['message'] ?? '');
    $m = preg_replace('/\s+/u', ' ', $m);
    if ($s !== '') $buf[] = "{$s}: {$m}";
  }
  return implode("\n", $buf);
}

function freq_stats(array $msgs): array {
  $counts = [];
  foreach ($msgs as $m) {
    $s = trim($m['sender'] ?? '');
    if ($s !== '') $counts[$s] = ($counts[$s] ?? 0) + 1;
  }
  arsort($counts);
  $total = array_sum($counts) ?: 1;
  $pct   = [];
  foreach ($counts as $k=>$v) $pct[$k] = round(100.0*$v/$total, 1);
  $top = $counts ? array_key_first($counts) : '';
  return ['counts'=>$counts, 'perc'=>$pct, 'top'=>$top, 'total'=>$total];
}

/** Additional analytics: latency, hours histogram, avg lengths, sentiment trend, topic keywords */
function compute_enriched_profile(array $messages, int $upload_id, PDO $pdo): array {
  $out = [];
  $by_sender = [];
  $timestamps = [];
  foreach ($messages as $m) {
    $s = trim($m['sender'] ?? '');
    $t = trim($m['message'] ?? '');
    $time = $m['time'] ?? null;
    if ($s === '' || $t === '') continue;
    $by_sender[$s][] = ['text'=>$t, 'time'=>$time];
    if ($time) {
      $ts = strtotime($time);
      if ($ts !== false) $timestamps[] = ['sender'=>$s,'time'=>$ts];
    }
  }

  // active hours histogram (0..23)
  $hours = array_fill(0,24,0);
  foreach ($timestamps as $row) {
    $h = (int)gmdate('G', $row['time']);
    $hours[$h]++;
  }
  $out['active_hours'] = $hours;

  // avg message length me vs others
  $stmt = $pdo->prepare("SELECT fullname FROM users WHERE id=?");
  $stmt->execute([$_SESSION['user_id']]);
  $reg_name = $stmt->fetchColumn() ?: '';
  $me = $reg_name;
  $lens = ['me'=>['count'=>0,'sum'=>0],'others'=>['count'=>0,'sum'=>0]];
  foreach ($messages as $m) {
    $s = trim($m['sender'] ?? ''); $t = trim($m['message'] ?? '');
    if ($t === '') continue;
    $len = mb_strlen($t);
    if ($s !== '' && mb_strtolower($s) === mb_strtolower($me)) {
      $lens['me']['count']++; $lens['me']['sum'] += $len;
    } else {
      $lens['others']['count']++; $lens['others']['sum'] += $len;
    }
  }
  $out['avg_length_me'] = $lens['me']['count'] ? round($lens['me']['sum'] / $lens['me']['count'],1) : 0;
  $out['avg_length_others'] = $lens['others']['count'] ? round($lens['others']['sum'] / $lens['others']['count'],1) : 0;

  // average reply latency approximation: other->me
  $lat_secs = [];
  $prev = null;
  foreach ($timestamps as $row) {
    if ($prev === null) { $prev = $row; continue; }
    if (mb_strtolower($prev['sender']) !== mb_strtolower($me) && mb_strtolower($row['sender']) === mb_strtolower($me)) {
      $lat_secs[] = max(0, $row['time'] - $prev['time']);
    }
    $prev = $row;
  }
  $out['avg_reply_latency_seconds'] = $lat_secs ? round(array_sum($lat_secs)/count($lat_secs)) : null;

  // sentiment simple scoring
  $pos_words = ['bom','gosto','adoro','excelente','sim','obrigad','obrigado','feliz','alegre'];
  $neg_words = ['mal','não','nao','triste','raiva','merda','chateado','mau','péssimo','raiva'];
  $scores = []; $cur = 0; $count = 0;
  foreach ($messages as $m) {
    $t = mb_strtolower($m['message'] ?? '');
    $val = 0;
    foreach ($pos_words as $w) if (mb_strpos($t,$w)!==false) $val++;
    foreach ($neg_words as $w) if (mb_strpos($t,$w)!==false) $val--;
    $cur += $val; $count++;
    if ($count >= 20) { $scores[] = $cur; $cur = 0; $count = 0; }
  }
  $out['sentiment_trend'] = count($scores) ? array_sum($scores)/count($scores) : null;

  // keywords (very simple top-words)
  $word_counts = [];
  foreach ($messages as $m) {
    $words = preg_split('/\s+/u', mb_strtolower($m['message'] ?? ''));
    foreach ($words as $w) {
      $w = preg_replace('/[^\p{L}\p{N}]+/u', '', $w);
      if (mb_strlen($w) > 2) $word_counts[$w] = ($word_counts[$w] ?? 0) + 1;
    }
  }
  arsort($word_counts);
  $out['keywords'] = array_slice(array_keys($word_counts), 0, 12);

  // top contacts
  $counts = [];
  foreach ($messages as $m) {
    $s = trim($m['sender'] ?? '');
    if ($s !== '') $counts[$s] = ($counts[$s] ?? 0) + 1;
  }
  arsort($counts);
  $out['top_contacts'] = array_slice($counts, 0, 6, true);

  return $out;
}

/** ---------------- MAIN ---------------- **/
$upload_id = (int)($_REQUEST['upload_id'] ?? 0);
$filepath = $_REQUEST['filepath'] ?? null;
if (!$upload_id && !$filepath) { echo json_encode(['ok'=>false,'error'=>'No file provided']); exit; }

if ($filepath && is_file($filepath)) {
  $txt = file_get_contents($filepath);
} else {
  $stmt = $pdo->prepare("SELECT filename FROM uploads WHERE id=? AND user_id=?");
  $stmt->execute([$upload_id, $_SESSION['user_id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || !isset($row['filename'])) { echo json_encode(['ok'=>false,'error'=>'File not found']); exit; }
  $txt = @file_get_contents(UPLOADS_DIR . '/' . $row['filename']);
  if ($txt === false) { echo json_encode(['ok'=>false,'error'=>'Unable to read file']); exit; }
}

$messages = parse_to_messages($txt);
$stats = freq_stats($messages);

$profile = [
  'relationship'    => 'unknown',
  'my_name_guess'   => $_SESSION['fullname'] ?? 'Eu',
  'other_primary'   => $stats['top'] ?? (array_key_first($stats['counts']) ?: ''),
  'tone'            => ['concise'],
  'style_notes'     => 'Sem dados robustos; manter coerência com as últimas mensagens.',
  'stats'           => [
    'total_messages' => $stats['total'],
    'by_user'        => $stats['counts'],
    'percent'        => $stats['perc'],
  ],
  'last_preview'    => build_sample($messages, 14),
];

// enriched analytics
$enriched = compute_enriched_profile($messages, $upload_id, $pdo);
$profile['enriched'] = $enriched;

// save profile JSON file
if (!is_dir(PROFILE_JSON_DIR)) @mkdir(PROFILE_JSON_DIR, 0755, true);
$jsonPath = rtrim(PROFILE_JSON_DIR, "\/\\") . DIRECTORY_SEPARATOR . ($upload_id ?: time()) . '.json';
@file_put_contents($jsonPath, json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// persist to DB if upload exists
if ($upload_id) {
  try {
    $stmt = $pdo->prepare("UPDATE uploads SET profile_json = ?, profile_updated_at = NOW() WHERE id = ? AND user_id = ?");
    $stmt->execute([json_encode($profile, JSON_UNESCAPED_UNICODE), $upload_id, $_SESSION['user_id']]);
  } catch (\Throwable $e) {
    // keep silent on DB write errors - profile file still saved
  }
}

echo json_encode(['ok'=>true,'profile'=>$profile], JSON_UNESCAPED_UNICODE);
exit;
