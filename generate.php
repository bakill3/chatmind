<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método inválido']); exit; }
if (!isset($_SESSION['user_id']))          { http_response_code(403); echo json_encode(['error' => 'Sem sessão']); exit; }

/* ---------- helpers ---------- */
if (!function_exists('to_utf8')) {
  function to_utf8($mixed) {
    if (is_array($mixed)) {
      $out = [];
      foreach ($mixed as $k=>$v) $out[to_utf8($k)] = to_utf8($v);
      return $out;
    }
    if (is_null($mixed)) return '';
    if (!is_string($mixed)) $mixed = (string)$mixed;
    if (!mb_detect_encoding($mixed, 'UTF-8', true)) {
      $t = @mb_convert_encoding($mixed, 'UTF-8', 'auto');
      if ($t !== false) $mixed = $t;
    }
    return $mixed;
  }
}

function body_param($key, $default = null) {
  static $json = null;
  if ($json === null) {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
      $raw = file_get_contents('php://input');
      $json = json_decode($raw, true);
      if (!is_array($json)) $json = [];
    } else {
      $json = [];
    }
  }
  if (array_key_exists($key, $json)) return $json[$key];
  return $_POST[$key] ?? $default;
}

function normalize_name($str) {
  if (function_exists('transliterator_transliterate')) {
    $str = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $str);
  } else {
    $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    if ($tmp !== false) $str = $tmp;
  }
  $str = preg_replace('/[^a-z0-9 ]/i', '', $str);
  return strtolower(trim($str));
}
function clamp($x,$a,$b){ return max($a,min($b,$x)); }
function is_attachment_or_system($t) {
  $t = mb_strtolower($t);
  $needles = [
    'imagem não revelada','image omitted',
    'vídeo não revelado','video omitted',
    'ficheiro de áudio não revelado','audio omitted',
    'sticker não revelado','gif omitido','gif omitted',
    'esta mensagem foi editada','mensagem apagada',
    'documento não revelado','chamada perdida',
  ];
  foreach ($needles as $n) if (mb_strpos($t, $n) !== false) return true;
  return false;
}
function clean_meta($t) {
  $t = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $t);
  $t = preg_replace('/<[^>]{0,80}>/u', '', $t);
  return trim($t);
}
function parse_whatsapp_txt($txt) {
  $lines = preg_split("/\R/u", $txt);
  $re = '/^\s*\[?(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\]?[\s\-–]*([^:]+):\s*(.*)$/u';
  $history = []; $names = [];
  $cur = null;
  foreach ($lines as $raw) {
    $line = to_utf8($raw);
    if (preg_match($re, $line, $m)) {
      if ($cur && $cur['message'] !== '') {
        $cur['message'] = clean_meta($cur['message']);
        if ($cur['message'] !== '' && !is_attachment_or_system($cur['message'])) {
          $history[] = $cur; $names[$cur['sender']] = true;
        }
      }
      $day = (int)$m[1]; $mon = (int)$m[2]; $yr = (int)$m[3];
      if ($yr < 100) { $yr += ($yr > 30 ? 1900 : 2000); }
      $hour = (int)$m[4]; $min = (int)$m[5]; $sec = isset($m[6]) ? (int)$m[6] : 0;
      $sender = trim(to_utf8($m[7] ?? ''));
      $msg    = trim(to_utf8($m[8] ?? ''));
      $time = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $yr, $mon, $day, $hour, $min, $sec);
      $cur = ['sender'=>$sender,'message'=>$msg,'time'=>$time];
    } else {
      if ($cur) {
        $append = trim(to_utf8($line));
        if ($append !== '') $cur['message'] .= ' ' . $append;
      }
    }
  }
  if ($cur && $cur['message'] !== '') {
    $cur['message'] = clean_meta($cur['message']);
    if ($cur['message'] !== '' && !is_attachment_or_system($cur['message'])) {
      $history[] = $cur; $names[$cur['sender']] = true;
    }
  }
  return [$history, array_keys($names)];
}
function sanitize_suggestion(string $s): string {
  $s = to_utf8($s);
  $replacements = [
    'Ã¡'=>'á','Ã©'=>'é','Ã­'=>'í','Ã³'=>'ó','Ãº'=>'ú',
    'Ã£'=>'ã','Ãµ'=>'õ','Ãª'=>'ê','Ã´'=>'ô','Ã§'=>'ç',
    'Ã€'=>'À','Ã‰'=>'É','Ã“'=>'Ó','Ã›'=>'Û',
    '\\u00a0' => ' ', '\\u2013' => '-', '\\u2019' => "'",
  ];
  $s = str_replace(array_keys($replacements), array_values($replacements), $s);
  $s = preg_replace('/\s+/u', ' ', trim($s));
  return $s;
}

/* ---------- warm endpoint ---------- */
if ((string)body_param('warm','0') === '1') {
  $modelAlias = defined('QUICK_MODEL_ALIAS') ? QUICK_MODEL_ALIAS : (defined('LLAMA_MODEL_ID') ? LLAMA_MODEL_ID : 'mini');
  $payload = [
    "model" => $modelAlias,
    "messages" => [
      ['role'=>'system','content'=>'ping'],
      ['role'=>'user','content'=>'ping']
    ],
    "temperature" => 0.1,
    "max_tokens"  => 4,
    "stream"      => false
  ];
  $ch = curl_init(api_url_for_model($modelAlias));
  curl_setopt_array($ch, [
    CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json; charset=utf-8'],
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  @curl_exec($ch); @curl_close($ch);
  echo json_encode(['ok'=>true,'warm'=>1]); exit;
}

/* ---------- input ---------- */
$upload_id      = (int)(body_param('upload_id', 0));
$received       = trim((string)body_param('received', ''));
$temp_pct       = (int)body_param('temp_pct', 60);
$allow_swear    = (string)body_param('allow_swear', '0') === '1';
$max_chars      = (int)body_param('max_reply_chars', 800);
$quick_mode     = ((string)body_param('quick', defined('QUICK_MODE_DEFAULT') && QUICK_MODE_DEFAULT ? '1' : '0')) === '1';
$model_override = trim((string)body_param('model_override', ''));

$use_formality  = (string)body_param('use_formality', '0') === '1';
$formality_pct  = (int)body_param('formality_pct', 50);
$contact_name   = trim((string)body_param('contact_name', ''));

$books = body_param('books', []);
if (!is_array($books)) {
  if (is_string($books) && strpos($books, ',') !== false) {
    $books = array_filter(array_map('trim', explode(',', $books)));
  } else {
    $books = $books ? [$books] : [];
  }
}

if (!$upload_id || $received === '') { echo json_encode(['error'=>'Dados inválidos']); exit; }

/* ---------- temperature ---------- */
$temp_min = defined('TEMP_MIN') ? (float)TEMP_MIN : 0.4;
$temp_max = defined('TEMP_MAX') ? (float)TEMP_MAX : 1.2;
$temp     = (float)($temp_min + ($temp_max - $temp_min) * (clamp($temp_pct,0,100)/100.0));
if (!$temp) $temp = defined('LLAMA_TEMP') ? (float)LLAMA_TEMP : 0.9;

/* ---------- books / boosters ---------- */
$BOOSTERS     = [];
$boost_text   = '';
$boost_titles = [];
$boost_rules  = [];

$boostFile = __DIR__ . '/assets/books_summaries.php';
if (is_file($boostFile)) {
  $BOOKS_SUMMARIES = include $boostFile; // expects return []
  if (is_array($BOOKS_SUMMARIES)) {
    foreach ($books as $bid) {
      if (!empty($BOOKS_SUMMARIES[$bid])) $BOOSTERS[$bid] = $BOOKS_SUMMARIES[$bid];
    }
  }
}
if (!$BOOSTERS && $books) {
  $id2title = [
    '48_laws'        => 'The 48 Laws of Power',
    'art_of_war'     => 'The Art of War',
    'prince'         => 'The Prince',
    '33_strat_war'   => 'The 33 Strategies of War',
    'influence'      => 'Influence',
    'models'         => 'Models',
    'game'           => 'The Game',
    'attached'       => 'Attached',
    'win_friends'    => 'How to Win Friends & Influence People',
    'art_of_seduction'=> 'The Art of Seduction',
    'atomic_habits'  => 'Atomic Habits',
    'deep_work'      => 'Deep Work',
  ];
  $generic_rules = [
    'Usa framing estratégico e linguagem assertiva',
    'Aplica princípios de reciprocidade e compromisso',
    'Considera alavancagem, timing e ancoragem',
    'Fala de forma concisa, direta e com intenção',
  ];
  foreach ($books as $bid) {
    $BOOSTERS[$bid] = ['title' => $id2title[$bid] ?? $bid, 'rules' => $generic_rules, 'weight'=> (defined('BOOKS_DEFAULT_WEIGHT') ? BOOKS_DEFAULT_WEIGHT : 0.6)];
  }
}
foreach ($BOOSTERS as $b) {
  if (!empty($b['title'])) $boost_titles[] = $b['title'];
  $rules = [];
  if (!empty($b['rules'])   && is_array($b['rules']))   $rules = array_merge($rules, $b['rules']);
  if (!empty($b['summary']) && is_string($b['summary'])) $rules[] = $b['summary'];
  foreach ($rules as $r) $boost_rules[] = $r;
}
$books_plan = '';
if ($boost_titles || $boost_rules) {
  $books_plan  = "Técnicas ativas (aplicar no texto, sem citar livros): ";
  $books_plan .= implode(' | ', array_slice($boost_rules ?: ['aplica um princípio claro'], 0, 10));
}
if ($boost_titles || $boost_rules) {
  $boost_text  = "ATIVA ESTILO — integra traços dos livros selecionados.\n";
  $boost_text .= "- Fontes: ".implode('; ', $boost_titles)."\n";
  if ($boost_rules) {
    $boost_text .= "- Técnicas/Princípios: ".implode(' | ', array_slice($boost_rules, 0, 18))."\n";
  }
  $boost_text .= "Regras de aplicação (OBRIGATÓRIO):\n";
  $boost_text .= "1) Mantém a minha VOZ; injeta vocabulário, enquadramentos e estrutura retórica coerentes com as fontes.\n";
  $boost_text .= "2) Em cada resposta, evidencia 1–2 técnicas de forma natural.\n";
  $boost_text .= "3) Fluidez acima de tudo.\n";
}

/* ---------- retriever helper ---------- */
$retrieverFile = __DIR__ . '/assets/retriever.php';
if (is_file($retrieverFile)) include_once $retrieverFile;

/* ---------- load upload ---------- */
$stmt = $pdo->prepare("SELECT filename, created_at FROM uploads WHERE id = ? AND user_id = ?");
$stmt->execute([$upload_id, $_SESSION['user_id']]);
$pdo->query("SET NAMES utf8mb4");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['error'=>'Upload não encontrado']); exit; }

$filepath = UPLOADS_DIR . '/' . $row['filename'];
if (!is_file($filepath))                { echo json_encode(['error'=>'Ficheiro TXT não existe']); exit; }
if (filesize($filepath) > 30*1024*1024) { echo json_encode(['error'=>'Ficheiro demasiado grande']); exit; }

$txt = to_utf8(file_get_contents($filepath));
list($history, $name_list) = parse_whatsapp_txt($txt);

/* ---------- model selection ---------- */
function select_model_alias($quick_mode, $override) {
  $override = trim((string)$override);
  if ($override !== '') return $override;
  if ($quick_mode && defined('QUICK_MODEL_ALIAS')) return QUICK_MODEL_ALIAS;
  return defined('LLAMA_MODEL_ID') ? LLAMA_MODEL_ID : 'chatmind';
}
function api_url_for_alias($alias) { return api_url_for_model($alias); }

/* ---------- CASE A: no history ---------- */
if (!$history) {
  $sysBase = "Responde em PT-PT. Sê claro, curto e direto.";
  if ($use_formality) {
    $f = clamp($formality_pct, 0, 100);
    $tone = ($f >= 70) ? "Muito formal" : (($f >= 50) ? "Formal" : (($f >= 30) ? "Neutro" : "Casual"));
    $sysBase .= "\nTom preferido: {$tone}.";
  }
  if ($boost_text) {
    $sysBase .= "\n".$boost_text."\n".($books_plan ? $books_plan."\n" : "");
    $sysBase .= "Prioridade: aplicar as técnicas de forma visível mas natural, mantendo a minha voz.\n";
  }

  $modelAlias = select_model_alias($quick_mode, $model_override);
  $payload = [
    "model" => $modelAlias,
    "messages" => [
      ['role'=>'system','content'=>$sysBase],
      ['role'=>'user','content'=>$received]
    ],
    "temperature" => $temp,
    "max_tokens"  => (defined('SUGGESTIONS_MAX_TOKENS') ? SUGGESTIONS_MAX_TOKENS : (defined('LLAMA_MAX_TOKENS') ? LLAMA_MAX_TOKENS : 256)),
    "stream"      => false
  ];
  $model_used = $payload['model'];
  $start_ts = microtime(true);

  $ch = curl_init(api_url_for_alias($modelAlias));
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  $response = curl_exec($ch);
  $errno = curl_errno($ch); $error = curl_error($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $elapsed_ms = (int)((microtime(true) - $start_ts) * 1000);

  if ($errno || !$response) {
    echo json_encode(['error' => "Erro ao contactar a IA: ".($error ?: "HTTP $httpCode"), 'debug' => ['model_used'=>$model_used, 'elapsed_ms'=>$elapsed_ms]]); exit;
  }
  $json  = json_decode($response, true);
  $reply = trim($json['choices'][0]['message']['content'] ?? '');
  if (!$reply) {
    echo json_encode(['error' => 'Resposta inesperada da IA', 'raw' => $response, 'debug' => ['model_used' => $model_used, 'elapsed_ms' => $elapsed_ms]]); exit;
  }
  $reply = to_utf8($reply);
  if ($max_chars > 0 && mb_strlen($reply) > $max_chars) $reply = mb_substr($reply, 0, $max_chars) . "...";
  echo json_encode([
    'ok'=>true,
    'reply'=>$reply,
    'model'=>$json['model'] ?? $model_used,
    'temp'=>$temp,
    'debug'=>['books'=>$books, 'boosted'=> (bool)$boost_text, 'formality_used'=>$use_formality ? $formality_pct : null, 'model_used'=>$model_used, 'elapsed_ms'=>$elapsed_ms]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- CASE B: history ---------- */
$stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$registered_name = $stmt->fetchColumn() ?: 'Eu';
$registered_norm = normalize_name($registered_name);
$my_name = $registered_name; $best = -1.0;
foreach ($name_list as $name) { $p = 0.0; similar_text(normalize_name($name), $registered_norm, $p); if ($p > $best) { $best = $p; $my_name = $name; } }

$RECENT_LIMIT = 600;
$recent = array_slice($history, -$RECENT_LIMIT);

$my_msgs = []; $other_names = [];
foreach ($recent as $h) {
  if ($h['sender'] === $my_name) $my_msgs[] = $h['message'];
  else $other_names[$h['sender']] = true;
}
$unique_others = array_values(array_filter(array_keys($other_names)));

$primary_other = $contact_name ?: 'Outro';
for ($i = count($recent)-1; $i >= 0; $i--) {
  if ($recent[$i]['sender'] !== $my_name) { $primary_other = $recent[$i]['sender']; break; }
}

$swears = ['caralho','merda','foda-se','fodasse','porra','puta','cabrao','cabrão','crl','fdp','estúpido','estupido'];
$swear_hits = 0;
foreach (array_slice(array_reverse($my_msgs),0,200) as $t) {
  foreach ($swears as $w) {
    if (stripos($t, $w) !== false) { $swear_hits++; break; }
  }
}
$can_swear = $allow_swear && ($swear_hits >= 1);

$total_len=0; $ex=0; $q=0; $emoji=0; $links=0; $openers=[]; $phrases=[];
foreach ($my_msgs as $t0) {
  $t = trim($t0); if ($t==='') continue;
  $len = mb_strlen($t); $total_len += $len;
  $ex += substr_count($t,'!'); $q += substr_count($t,'?');
  if (preg_match('/[\x{1F300}-\x{1FAFF}]/u', $t)) $emoji++;
  if (preg_match('/\bhttps?:\/\//i', $t)) $links++;
  $words = preg_split('/\s+/', mb_strtolower($t));
  if ($words && count($words)>=2) $openers[$words[0].' '.$words[1]] = ($openers[$words[0].' '.$words[1]] ?? 0) + 1;
  for ($i=0; $i<count($words)-1; $i++) {
    $bi = $words[$i].' '.$words[$i+1]; $phrases[$bi] = ($phrases[$bi] ?? 0) + 1;
    if ($i < count($words)-2) { $tri = $bi.' '.$words[$i+2]; $phrases[$tri] = ($phrases[$tri] ?? 0) + 1; }
  }
}
$avg_len = $my_msgs ? round($total_len / count($my_msgs)) : 0;
arsort($openers); $top_openers = array_slice(array_keys($openers), 0, 5);
arsort($phrases); $top_phr     = array_slice(array_keys($phrases), 0, 8);

$style_summary = [
  "Nome do utilizador"    => $my_name,
  "Interlocutores"        => $unique_others ? implode(', ', $unique_others) : "(n/d)",
  "Comprimento médio"     => $avg_len . " chars",
  "Sinais comuns"         => "!'s=$ex, ?'s=$q, emojis≈$emoji, links≈$links",
  "Aberturas comuns"      => $top_openers ? implode(' | ', $top_openers) : "(sem padrão forte)",
  "Expressões/phrases"    => $top_phr ? implode(' | ', $top_phr) : "(neutro)",
  "Palavrões permitidos?" => $can_swear ? "sim" : "não"
];

$profile_path = rtrim(PROFILE_JSON_DIR, "\/\\") . DIRECTORY_SEPARATOR . $upload_id . '.json';
$profile_dir = dirname($profile_path);
if (!is_dir($profile_dir)) @mkdir($profile_dir, 0755, true);
$enriched_profile = null;
if (is_file($profile_path)) {
  $rawp = @file_get_contents($profile_path);
  $decoded = $rawp ? json_decode($rawp, true) : null;
  if (is_array($decoded)) $enriched_profile = $decoded;
}
$upload_ct = isset($row['created_at']) ? strtotime($row['created_at']) : null;
$need_rebuild = !$enriched_profile || (@filemtime($profile_path) === false) || ($upload_ct !== null && @filemtime($profile_path) < $upload_ct);
if ($need_rebuild && empty($_SESSION['profile_rebuilt_for_'.$upload_id])) {
  $builder = __DIR__ . '/assets/profile_builder.php';
  if (is_file($builder)) { include_once $builder; if (function_exists('rebuild_profile')) { $reb = rebuild_profile($upload_id, $pdo, $_SESSION['user_id']); if ($reb) { $enriched_profile = $reb; $_SESSION['profile_rebuilt_for_'.$upload_id]=1; } } }
}

/* few-shot pairs from history */
$shots = [];
for ($i = count($recent)-1; $i >= 1 && count($shots) < (defined('FEWSHOT_PAIRS') ? FEWSHOT_PAIRS : 8); $i--) {
  $a = $recent[$i-1]; $b = $recent[$i];
  if ($a['sender'] !== $my_name && $b['sender'] === $my_name && $a['message'] !== '' && $b['message'] !== '') {
    $shots[] = [$a['message'], $b['message']];
  }
}
$shots = array_reverse($shots);

/* approved memory */
$approved_pairs = [];
try {
  $stmt = $pdo->prepare("
    SELECT received_text, approved_reply, is_approved, (score_up - score_down) AS score
    FROM approved_messages
    WHERE user_id = ?
      AND (
            (upload_id = ?)
         OR (contact_name = ? AND contact_name IS NOT NULL)
          )
    ORDER BY is_approved DESC, score DESC, id DESC
    LIMIT 24
  ");
  $stmt->execute([$_SESSION['user_id'], $upload_id, $primary_other]);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rx = trim($r['received_text'] ?? ''); $ax = trim($r['approved_reply'] ?? '');
    if ($rx !== '' && $ax !== '') $approved_pairs[] = [$rx, $ax];
  }
} catch (\Throwable $e) {}

/* build messages */
$messages = [];
$lang_note = 'Sempre responde em ' . (defined('DEFAULT_TARGET_LANG') ? DEFAULT_TARGET_LANG : 'pt-PT') . '. Usa ortografia e formas de PT-PT.';
$messages[] = ['role'=>'system','content'=>$lang_note];

$systemRules =
"Escreve **exatamente** no estilo do utilizador \"{$my_name}\" (PT-PT).
Objetivo: responder como o {$my_name}, coerente com o histórico recente.
Regras:
- Nada de meta/IA. Nunca digas \"sou um modelo\".
- Mantém o comprimento e o tom aproximados ao histórico.
- Se te pedirem opinião, dá conselho **geral** (anónimo) sem recolher dados pessoais; faz UMA pergunta curta para contexto quando útil.
- Em convites, **não decides por mim**: pergunta dia/hora/local OU sugere 1–2 opções.
- Palavrões: ".($can_swear ? "permitidos se natural" : "evita").".
- Emojis só se o meu estilo os usar com frequência.
- Se faltar contexto: sê honesto e curto.
Resumo de estilo:
" . json_encode($style_summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

if ($use_formality) {
  $f = clamp($formality_pct, 0, 100);
  $tone = ($f >= 70) ? "Muito formal" : (($f >= 50) ? "Formal" : (($f >= 30) ? "Neutro" : "Casual"));
  $systemRules .= "\n\nPreferência de tom: {$tone}. Ajusta a sintaxe e escolha de palavras a este nível.";
}
if (!empty($boost_text)) {
  $systemRules .= "\n\n".$boost_text.
    "\n".($books_plan ? $books_plan."\n" : "").
    "PRIORIDADE ABSOLUTA: EM TODA A SUGESTÃO, usa pelo menos 1 técnica dos livros selecionados de forma VISÍVEL mas natural, como se fosses eu. No fim da sugestão, põe entre parêntesis [técnica usada: ...]. Se não fizer sentido, diz 'sem técnica aplicada'. Mantém sempre a minha voz e o contexto.";
}
$messages[] = ['role' => 'system', 'content' => $systemRules];

/* book hint (explicit) */
$books_hint = '';
if (!empty($BOOSTERS)) {
  $parts = [];
  foreach ($BOOSTERS as $bid => $b) {
    $weight = $b['weight'] ?? (defined('BOOKS_DEFAULT_WEIGHT') ? BOOKS_DEFAULT_WEIGHT : 0.6);
    $summary = $b['summary'] ?? ($b['rules'] ? implode(' | ', $b['rules']) : ($b['title'] ?? $bid));
    $parts[] = sprintf("[%s|w=%.2f] %s", ($b['title'] ?? $bid), $weight, $summary);
  }
  if ($parts) $books_hint = "APLICAR LIVROS (peso ajustável): \n" . implode("\n", $parts) . "\n";
}
if ($books_hint) $messages[] = ['role'=>'system','content'=>$books_hint];

/* approved few-shots */
if (!empty($approved_pairs)) {
  $APPR_MAX = defined('FEWSHOT_PAIRS') ? (int)FEWSHOT_PAIRS : 8;
  foreach (array_slice($approved_pairs, 0, $APPR_MAX) as [$rx, $ax]) {
    $messages[] = ['role' => 'user',      'content' => $rx];
    $messages[] = ['role' => 'assistant', 'content' => $ax];
  }
}

/* conversational few-shots */
foreach ($shots as [$u, $me]) {
  $messages[] = ['role'=>'user','content'=>$u];
  $messages[] = ['role'=>'assistant','content'=>$me];
}

/* tail for recency */
$TAIL_TURNS_LOCAL = ($quick_mode ? (defined('TAIL_TURNS_QUICK') ? (int)TAIL_TURNS_QUICK : 24) : min((defined('TAIL_TURNS') ? (int)TAIL_TURNS : 200), 200));
$tail = array_slice($recent, max(0, count($recent) - $TAIL_TURNS_LOCAL));
foreach ($tail as $t) {
  $role = ($t['sender'] === $my_name) ? 'assistant' : 'user';
  if ($t['message'] !== '') $messages[] = ['role'=>$role, 'content'=>$t['message']];
}

/* retriever */
if (function_exists('retrieve_similar_snippets')) {
  $corpus = $history;
  $query_text = $received;
  $topk = defined('RETRIEVER_TOPK') ? (int)RETRIEVER_TOPK : 6;
  $snips = retrieve_similar_snippets($corpus, $query_text, $topk, 20);
  if ($snips && is_array($snips)) {
    $sn_text = "Contexto semelhante (trechos do historial):\n";
    foreach ($snips as $s) {
      $sn_text .= sprintf("- (%0.2f) %s\n", $s['score'], $s['text']);
    }
    $messages[] = ['role'=>'system','content'=>$sn_text];
  }
}

/* exemplars again (topK) */
$exemplars = [];
if (!empty($approved_pairs)) $exemplars = array_slice($approved_pairs, 0, (defined('EXEMPLARS_TOPK') ? (int)EXEMPLARS_TOPK : 6));
foreach ($exemplars as [$rx,$ax]) {
  $messages[] = ['role'=>'user','content'=>$rx];
  $messages[] = ['role'=>'assistant','content'=>$ax];
}

/* JSON output instructions */
array_unshift($messages, ['role'=>'system','content'=>"OUTPUT FORMAT (PT-PT): Responde estritamente em PT-PT. Return a JSON array named 'suggestions' containing 3-6 objects. Each object must have: 'text' (PT-PT), 'tone' (uma palavra), 'length' (short|medium|long), 'why' (1–2 frases). NÃO OUTPUT nada fora do JSON. Output valid JSON only."]);

/* choose model/endpoint */
$modelAlias = select_model_alias($quick_mode, $model_override);
$apiUrl     = api_url_for_alias($modelAlias);

$payload = [
  "model"       => $modelAlias,
  "messages"    => $messages,
  "temperature" => $temp,
  "max_tokens"  => (defined('SUGGESTIONS_MAX_TOKENS') ? SUGGESTIONS_MAX_TOKENS : (defined('LLAMA_MAX_TOKENS') ? LLAMA_MAX_TOKENS : 256)),
  "stream"      => false
];
$model_used = $payload['model'];
$start_ts = microtime(true);

/* log prompt for debugging */
$logdir = __DIR__ . '/logs'; if (!is_dir($logdir)) @mkdir($logdir, 0755, true);
@file_put_contents($logdir.'/last_messages.json', json_encode(['model'=>$model_used,'messages'=>$messages], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 120,
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$response = curl_exec($ch);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$elapsed_ms = (int)( (microtime(true) - $start_ts) * 1000 );

$logfile = $logdir . '/generate.log';
$logEntry = date('c') . " | upload_id={$upload_id} | user_id=" . ($_SESSION['user_id'] ?? 'n') . " | model={$model_used} | elapsed_ms={$elapsed_ms} | http={$httpCode}\n";
@file_put_contents($logfile, $logEntry, FILE_APPEND | LOCK_EX);

if ($errno) {
  echo json_encode(['error' => "Erro ao contactar a IA: $error", 'debug' => ['model_used' => $model_used, 'elapsed_ms'=>$elapsed_ms]]); exit;
}
if (!$response) {
  echo json_encode(['error' => "Resposta vazia da IA (HTTP $httpCode)", 'debug' => ['model_used' => $model_used, 'elapsed_ms'=>$elapsed_ms]]); exit;
}

$json  = json_decode($response, true);
$raw_reply = $json['choices'][0]['message']['content'] ?? null;
if (!$raw_reply) {
  $entry = date('c') . " | upload_id={$upload_id} | user_id=" . ($_SESSION['user_id'] ?? 'n') . " | http={$httpCode} | raw_response=" . substr($response ?? '',0,2000) . "\n";
  @file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
  $fallback = 'Desculpa, algo correu mal. Queres que tente uma resposta curta agora?';
  echo json_encode(['ok'=>true,'reply_text'=>$fallback,'model'=>$json['model'] ?? $model_used,'temp'=>$temp,'debug'=>['note'=>'empty_model_response','books'=>$books,'model_used'=>$model_used,'elapsed_ms'=>$elapsed_ms]], JSON_UNESCAPED_UNICODE);
  exit;
}

$raw_reply = to_utf8(trim($raw_reply));

/* refusal detection + safe retry (short) */
$refusal_signals = [
  'não posso', 'não posso ajudar', 'não posso fornecer', 'desculpe, não', 'não é apropriado',
  'não tenho como', 'não posso comentar', 'peço desculpa, mas não posso', 'peço desculpas, mas não posso'
];
$gave_refusal = false;
$lower_raw = mb_strtolower($raw_reply);
foreach ($refusal_signals as $sig) { if (mb_strpos($lower_raw, $sig) !== false) { $gave_refusal = true; break; } }

if ($gave_refusal) {
  $retry_system = "Responde em PT-PT. Não forneças dados pessoais. Dá 3 opções práticas e 1 pergunta curta de contexto. Mantém curto.";
  $retry_payload = [
    "model" => $modelAlias,
    "messages" => [
      ['role'=>'system','content'=>$retry_system],
      ['role'=>'user','content'=>"Contexto: {$received}\nResponde com 3 opções curtas + 1 pergunta."]
    ],
    "temperature" => $temp,
    "max_tokens"  => (defined('SUGGESTIONS_MAX_TOKENS') ? SUGGESTIONS_MAX_TOKENS : 200),
    "stream"      => false
  ];
  $ch2 = curl_init($apiUrl);
  curl_setopt_array($ch2, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_POSTFIELDS => json_encode($retry_payload, JSON_UNESCAPED_UNICODE),
  ]);
  $resp2 = curl_exec($ch2);
  $errno2 = curl_errno($ch2); $err2 = curl_error($ch2); curl_close($ch2);
  if (!$errno2 && $resp2) {
    $j2 = json_decode($resp2, true);
    $try_reply = trim($j2['choices'][0]['message']['content'] ?? '');
    if ($try_reply) $raw_reply = "[RETRY]\n" . to_utf8($try_reply);
  }
}

/* Try to parse JSON suggestions */
$suggestions = null;
if (strpos($raw_reply, '{') !== false || strpos($raw_reply, '[') !== false) {
  $firstBrace = strpos($raw_reply, '{');
  $firstBracket = strpos($raw_reply, '[');
  if ($firstBrace === false && $firstBracket === false) { /* nothing */ }
  else {
    $start = ($firstBracket !== false && ($firstBracket < $firstBrace || $firstBrace === false)) ? $firstBracket : $firstBrace;
    if ($start !== false) {
      $jsonPart = substr($raw_reply, $start);
      $decoded = json_decode($jsonPart, true);
      if (!is_array($decoded)) {
        $lastBracket = strrpos($jsonPart, ']');
        $lastBrace   = strrpos($jsonPart, '}');
        $endPos = max($lastBracket, $lastBrace);
        if ($endPos !== false) {
          $jsonPartTrim = substr($jsonPart, 0, $endPos+1);
          $decoded = json_decode($jsonPartTrim, true);
        }
      }
      if (is_array($decoded)) {
        if (isset($decoded['suggestions']) && is_array($decoded['suggestions'])) $suggestions = $decoded['suggestions'];
        elseif (isset($decoded[0])) $suggestions = $decoded;
      }
    }
  }
}

/* fallback: try small normalization and parse, else return raw */
if (!is_array($suggestions)) {
  $parseLog = $logdir . '/generate_parse.log';
  $entry = date('c') . " | upload_id={$upload_id} | user_id=" . ($_SESSION['user_id'] ?? 'n') . " | parse_fail\n";
  $entry .= "RAW: " . substr($raw_reply ?? '',0,4000) . "\n";
  @file_put_contents($parseLog, $entry, FILE_APPEND | LOCK_EX);

  $normalized = $raw_reply;
  $normalized = preg_replace('/\[\s*"?suggestions"?\s*=\s*\[/i', '{"suggestions": [', $normalized);
  $normalized = preg_replace('/"?suggestions"?\s*=\s*/i', '"suggestions": ', $normalized);
  $normalized_try = str_replace("'", '"', $normalized);
  $decoded2 = json_decode($normalized_try, true);
  if (is_array($decoded2) && (isset($decoded2['suggestions']) || isset($decoded2[0]))) {
    if (isset($decoded2['suggestions']) && is_array($decoded2['suggestions'])) {
      $suggestions = $decoded2['suggestions'];
    } elseif (isset($decoded2[0])) {
      $suggestions = $decoded2;
    }
    $recEntry = date('c') . " | upload_id={$upload_id} | parse_recovered\n";
    $recEntry .= "NORMALIZED: " . substr($normalized_try,0,4000) . "\n";
    @file_put_contents($parseLog, $recEntry, FILE_APPEND | LOCK_EX);
  }

  if (!is_array($suggestions)) {
    $reply = $raw_reply;
    if ($max_chars > 0 && mb_strlen($reply) > $max_chars) $reply = mb_substr($reply, 0, $max_chars) . "...";
    echo json_encode([
      'ok'=>true,
      'reply_text'=>$reply,
      'model'=>$json['model'] ?? $model_used,
      'temp'=>$temp,
      'debug'=>[
        'books'=>$books,
        'boosted'=>(bool)$boost_text,
        'boost_titles'=>$boost_titles,
        'rules_used'=>array_slice($boost_rules,0,10),
        'formality_used'=>$use_formality? $formality_pct:null,
        'raw_reply'=>$raw_reply,
        'model_used'=>$model_used,
        'elapsed_ms'=>$elapsed_ms
      ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* Rank and sanitize suggestions */
$ranked = [];
foreach ($suggestions as $s) {
  $text = sanitize_suggestion(trim($s['text'] ?? ''));
  $tone = sanitize_suggestion(trim($s['tone'] ?? 'neutral'));
  $length = sanitize_suggestion(trim($s['length'] ?? ''));
  if ($length === '') $length = (mb_strlen($text) < 80 ? 'short' : (mb_strlen($text) < 220 ? 'medium' : 'long'));
  $why = sanitize_suggestion(trim($s['why'] ?? ''));
  $score = 0.0;

  $lat = $enriched_profile['enriched']['avg_reply_latency_seconds'] ?? ($enriched_profile['avg_reply_latency_seconds'] ?? null);
  if ($lat !== null) {
    if ($lat > 3600) { if ($length === 'short') $score += 1.5; elseif ($length === 'medium') $score += 0.5; }
    elseif ($lat > 300) { if ($length === 'short') $score += 0.8; elseif ($length === 'medium') $score += 0.3; }
  }
  $keywords = $enriched_profile['enriched']['keywords'] ?? ($enriched_profile['keywords'] ?? []);
  if (is_array($keywords) && in_array('brincar', $keywords)) {
    if ($tone === 'playful' || stripos($text, '!') !== false) $score += 0.6;
  }
  $ranked[] = ['text'=>$text,'tone'=>$tone,'length'=>$length,'why'=>$why,'score'=>$score];
}
usort($ranked, fn($a,$b)=>($b['score'] <=> $a['score']));

echo json_encode([
  'ok'=>true,
  'suggestions'=>$ranked,
  'model'=>$json['model'] ?? $model_used,
  'temp'=>$temp,
  'debug'=>[
    'books'=>$books,
    'boosted'=>(bool)$boost_text,
    'boost_titles'=>$boost_titles,
    'model_used'=>$model_used,
    'elapsed_ms'=>$elapsed_ms
  ]
], JSON_UNESCAPED_UNICODE);
exit;
