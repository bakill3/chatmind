<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'error'=>'unauth']); exit; }

/** ---- Encoding helpers ---- */
function normalize_to_utf8(string $raw): string {
  if ($raw === '') return '';
  if (isset($raw[1])) {
    $bom2 = $raw[0] . $raw[1];
    if ($bom2 === "\xFF\xFE") { return function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : iconv('UTF-16LE','UTF-8//IGNORE',$raw); }
    if ($bom2 === "\xFE\xFF") { return function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : iconv('UTF-16BE','UTF-8//IGNORE',$raw); }
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
    if (!mb_detect_encoding($s, 'UTF-8', true)) $s = mb_convert_encoding($s, 'UTF-8', 'auto');
    return $s;
  }
  return $mixed;
}

// WhatsApp parse
function start_re(): string {
  // Matches both iOS and Android export formats
  return '/^(?:\[(\d{1,2}\/\d{1,2}\/\d{2,4}),\s+(\d{1,2}:\d{2}(?::\d{2})?)\]\s|'
       .       '(\d{1,2}\/\d{1,2}\/\d{2,4}),\s+(\d{1,2}:\d{2}(?::\d{2})?)\s-\s)'
       . '([^:]+):\s(.*)$/u';
}
function parse_to_messages(string $txt): array {
  $txt = normalize_to_utf8($txt);
  $txt = str_replace("\r\n", "\n", $txt);
  $lines = explode("\n", $txt);
  $re = start_re();

  $out = [];
  foreach ($lines as $line) {
    if ($line === '') continue;
    if (preg_match($re, $line, $m)) {
      $name = trim($m[5]);
      $msg  = $m[6];
      $out[] = ['sender'=>$name, 'message'=>$msg];
    } else {
      if (!empty($out)) $out[count($out)-1]['message'] .= "\n".$line;
    }
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
    $t = mb_strtolower($m['message']);
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

/** ---- Input & file ---- */
$upload_id = (int)($_POST['upload_id'] ?? 0);
if ($upload_id <= 0) { echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }

$stmt = $pdo->prepare("SELECT * FROM uploads WHERE id=? AND user_id=?");
$stmt->execute([$upload_id, $_SESSION['user_id']]);
$up = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$up) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

$filepath = UPLOADS_DIR . '/' . $up['filename'];
if (!is_file($filepath)) { echo json_encode(['ok'=>false,'error'=>'nofile']); exit; }

/** ---- Parse & prepare ---- */
$txt = file_get_contents($filepath);
$messages = parse_to_messages($txt);
if (!$messages) { echo json_encode(['ok'=>false,'error'=>'parse_empty']); exit; }
$messages = array_map('utf8ize', $messages); // safety

$sample_text = build_sample($messages, 140);

$stmt = $pdo->prepare("SELECT fullname FROM users WHERE id=?");
$stmt->execute([$_SESSION['user_id']]);
$reg_name = $stmt->fetchColumn() ?: 'Eu';

$sys = "És uma ferramenta que analisa excertos de conversas WhatsApp e devolve **APENAS JSON válido**.";
$user = <<<TXT
Analisa o excerto abaixo e responde **APENAS** com JSON válido, no seguinte formato:

{
  "profile": {
    "relationship": "friend|romantic|family|professional|unknown",
    "my_name_guess": "string",
    "other_primary": "string",
    "tone": ["concise","playful","sarcastic","supportive","formal","flirty","dry"],
    "style_notes": "1-2 linhas",
    "boundaries": "1 linha"
  },
  "conv_summary": "2–3 frases, PT-PT, clima + temas principais"
}

Regras:
- Nada de texto fora do JSON.
- "other_primary" = quem mais interage comigo no excerto (se dúvida, string vazia).
- Sê conciso e objetivo.

Registo de nome (pode ajudar): {$reg_name}

Conversa (amostra):
{$sample_text}
TXT;

$payload = [
  'model' => LLAMA_MODEL_ID,
  'messages' => [
    ['role' => 'system', 'content' => $sys],
    ['role' => 'user',   'content' => $user],
  ],
  'temperature' => 0.2,
  'max_tokens'  => 350,
  'stream'      => false
];

$ch = curl_init(LLAMA_API_URL);
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 5,   
  CURLOPT_TIMEOUT        => 18,  
]);
$res  = curl_exec($ch);
$err  = curl_error($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$profile = null;
$conv_summary = '';
if (!$err && $res && $http < 400) {
  $data = json_decode($res, true);
  $content = $data['choices'][0]['message']['content'] ?? '';
  if ($content) {
    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
      if (isset($decoded['profile']) && is_array($decoded['profile'])) $profile = $decoded['profile'];
      if (!empty($decoded['conv_summary'])) $conv_summary = (string)$decoded['conv_summary'];
    }
    // backward compatibility if the model returned the old flat structure
    if (!$profile) {
      $maybe = json_decode($content, true);
      if (is_array($maybe) && isset($maybe['relationship'])) $profile = $maybe;
    }
  }
}

/** ---- Local fallback + stats ---- */
$stats = freq_stats($messages);
$last_lines = array_slice($messages, -6);
$last_sample = [];
foreach ($last_lines as $m) {
  $s = trim($m['sender'] ?? ''); $t = trim($m['message'] ?? '');
  if ($s !== '' && $t !== '') $last_sample[] = "{$s}: {$t}";
}
$last_snippet = implode("\n", $last_sample);

if (!is_array($profile)) {
  // no LLM or timeout -> minimal profile
  $profile = [
    'relationship'  => 'unknown',
    'my_name_guess' => $reg_name,
    'other_primary' => $stats['top'] ?: '',
    'tone'          => ['concise'],
    'style_notes'   => 'Sem dados robustos; manter coerência com as últimas mensagens.',
    'boundaries'    => ''
  ];
}

/** ---- Ensure UTF‑8 and sanity fixes ---- */
$profile = utf8ize($profile);
$conv_summary = utf8ize($conv_summary);

// if model guessed me as other_primary, fix to top other by frequency
$my_guess = trim((string)($profile['my_name_guess'] ?? $reg_name));
$other_guess = trim((string)($profile['other_primary'] ?? ''));
if ($other_guess !== '' && mb_strtolower($other_guess) === mb_strtolower($my_guess)) {
  // pick top speaker that isn't me
  $top = '';
  foreach (array_keys($stats['counts']) as $name) {
    if (mb_strtolower($name) !== mb_strtolower($my_guess)) { $top = $name; break; }
  }
  $profile['other_primary'] = $top;
}

/** ---- Attach stats & last preview ---- */
$profile['stats'] = [
  'total_messages' => $stats['total'],
  'by_user'        => $stats['counts'],
  'percent'        => $stats['perc']
];
$profile['last_preview'] = $last_snippet;
if ($conv_summary) $profile['conv_summary'] = $conv_summary;

/** ---- Persist ---- */
$stmt = $pdo->prepare("UPDATE uploads SET profile_json=?, profile_updated_at=NOW() WHERE id=?");
$stmt->execute([json_encode($profile, JSON_UNESCAPED_UNICODE), $upload_id]);

/** ---- Build popover HTML ---- */
$sumParts = [];
if (!empty($profile['relationship']))     $sumParts[] = '<li><b>Relação:</b> '.htmlspecialchars($profile['relationship']).'</li>';
if (!empty($profile['my_name_guess']))    $sumParts[] = '<li><b>Tu:</b> '.htmlspecialchars($profile['my_name_guess']).'</li>';
if (!empty($profile['other_primary']))    $sumParts[] = '<li><b>Outro:</b> '.htmlspecialchars($profile['other_primary']).'</li>';
if (!empty($profile['stats']['total_messages'])) {
  $sumParts[] = '<li><b>Mensagens:</b> '.$profile['stats']['total_messages'].'</li>';
  $perc = $profile['stats']['percent'] ?? [];
  if ($perc && is_array($perc)) {
    arsort($perc);
    $chunks = [];
    foreach (array_slice($perc,0,2,true) as $name=>$p) $chunks[] = htmlspecialchars($name).": ".$p."%";
    if ($chunks) $sumParts[] = '<li><b>Participação:</b> '.implode(' · ', $chunks).'</li>';
  }
}
if (!empty($profile['tone']) && is_array($profile['tone'])) $sumParts[] = '<li><b>Tom:</b> '.htmlspecialchars(implode(', ', $profile['tone'])).'</li>';
if (!empty($profile['style_notes']))                      $sumParts[] = '<li>'.htmlspecialchars($profile['style_notes']).'</li>';
if (!empty($conv_summary))                                $sumParts[] = '<li><b>Resumo:</b> '.htmlspecialchars($conv_summary).'</li>';
$summary = $sumParts ? '<ul class="m-0 ps-3">'.implode('', $sumParts).'</ul>' : '';

echo json_encode(['ok'=>true, 'profile'=>$profile, 'summary'=>$summary], JSON_UNESCAPED_UNICODE);
