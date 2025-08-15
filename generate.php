<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método inválido']); exit; }
if (!isset($_SESSION['user_id']))          { http_response_code(403); echo json_encode(['error' => 'Sem sessão']); exit; }

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
function to_utf8($s) {
  if (is_string($s) && !mb_detect_encoding($s, 'UTF-8', true)) return mb_convert_encoding($s, 'UTF-8', 'auto');
  return $s;
}
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
  $re = '/^\[?(\d{1,2}\/\d{1,2}\/\d{2,4}),\s+(\d{2}:\d{2}(?::\d{2})?)\]?\s([^:]+):\s(.*)$/u';
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
      $sender = trim($m[3]);
      $msg    = trim($m[4]);
      $cur = [
        'sender'  => $sender,
        'message' => $msg,
        'time'    => $m[1] . ' ' . $m[2],
      ];
    } else {
      if ($cur) {
        $append = trim($line);
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


$upload_id     = (int)(body_param('upload_id', 0));
$received      = trim((string)body_param('received', ''));
$temp_pct      = (int)body_param('temp_pct', 60);
$allow_swear   = (string)body_param('allow_swear', '0') === '1';
$max_chars     = (int)body_param('max_reply_chars', 800);

$use_formality = (string)body_param('use_formality', '0') === '1';
$formality_pct = (int)body_param('formality_pct', 50);

$books = body_param('books', []);
if (!is_array($books)) {
  // accept CSV fallback
  if (is_string($books) && strpos($books, ',') !== false) {
    $books = array_filter(array_map('trim', explode(',', $books)));
  } else {
    $books = $books ? [$books] : [];
  }
}

if (!$upload_id || $received === '') { echo json_encode(['error'=>'Dados inválidos']); exit; }


$temp_min = defined('TEMP_MIN') ? (float)TEMP_MIN : 0.4;
$temp_max = defined('TEMP_MAX') ? (float)TEMP_MAX : 1.2;
$temp     = (float)($temp_min + ($temp_max - $temp_min) * (clamp($temp_pct,0,100)/100.0));
if (!$temp) $temp = defined('LLAMA_TEMP') ? (float)LLAMA_TEMP : 0.9;


$BOOSTERS     = [];
$boost_text   = '';
$boost_titles = [];
$boost_rules  = [];

/* local summaries file is optional but preferred */
$boostFile = __DIR__ . '/assets/books_summaries.php';
if (is_file($boostFile)) {
  $BOOKS_SUMMARIES = [];
  include $boostFile; // expects $BOOKS_SUMMARIES = ['id' => ['title'=>'','rules'=>['..','..']]]
  foreach ($books as $bid) {
    if (!empty($BOOKS_SUMMARIES[$bid])) $BOOSTERS[$bid] = $BOOKS_SUMMARIES[$bid];
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
    'mindset'        => 'Mindset',
    'cant_hurt_me'   => 'Can’t Hurt Me',
    'subtle_art'     => 'The Subtle Art of Not Giving a F*ck',
    'never_split'    => 'Never Split the Difference',
    'blue_ocean'     => 'Blue Ocean Strategy',
    'getting_to_yes' => 'Getting to Yes',
    'good_to_great'  => 'Good to Great',
    'lean_startup'   => 'The Lean Startup',
  ];
  $generic_rules = [
    'Usa framing estratégico e linguagem assertiva',
    'Aplica princípios de reciprocidade e compromisso',
    'Considera alavancagem, timing e ancoragem',
    'Fala de forma concisa, direta e com intenção',
  ];
  foreach ($books as $bid) {
    $BOOSTERS[$bid] = [
      'title' => $id2title[$bid] ?? $bid,
      'rules' => $generic_rules,
    ];
  }
}

/* flatten boosters */
foreach ($BOOSTERS as $b) {
  if (!empty($b['title'])) $boost_titles[] = $b['title'];
  if (!empty($b['rules']) && is_array($b['rules'])) {
    foreach ($b['rules'] as $r) $boost_rules[] = $r;
  }
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
  $boost_text .= "2) Em cada resposta, evidencia 1–2 técnicas de forma natural (ex.: ancoragem, framing, persuasão, máximas de estratégia) sem citar livros.\n";
  $boost_text .= "3) Não soar a citação; fluidez acima de tudo.\n";
}

$stmt = $pdo->prepare("SELECT filename FROM uploads WHERE id = ? AND user_id = ?");
$stmt->execute([$upload_id, $_SESSION['user_id']]);
$pdo->query("SET NAMES utf8mb4");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['error'=>'Upload não encontrado']); exit; }

$filepath = UPLOADS_DIR . '/' . $row['filename'];
if (!is_file($filepath))                { echo json_encode(['error'=>'Ficheiro TXT não existe']); exit; }
if (filesize($filepath) > 30*1024*1024) { echo json_encode(['error'=>'Ficheiro demasiado grande']); exit; }

$txt = to_utf8(file_get_contents($filepath));
list($history, $name_list) = parse_whatsapp_txt($txt);

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

  $payload = [
    "model" => LLAMA_MODEL_ID,
    "messages" => [
      ['role'=>'system','content'=>$sysBase],
      ['role'=>'user','content'=>$received]
    ],
    "temperature" => $temp,
    "max_tokens"  => LLAMA_MAX_TOKENS,
    "stream"      => false
  ];
  $ch = curl_init(LLAMA_API_URL);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  $response = curl_exec($ch);
  $errno = curl_errno($ch); $error = curl_error($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($errno || !$response) { echo json_encode(['error'=>"Erro ao contactar a IA: ".($error ?: "HTTP $httpCode")]); exit; }
  $json  = json_decode($response, true);
  $reply = trim($json['choices'][0]['message']['content'] ?? '');
  if (!$reply) { echo json_encode(['error'=>'Resposta inesperada da IA','raw'=>$response]); exit; }
  if ($max_chars > 0 && mb_strlen($reply) > $max_chars) $reply = mb_substr($reply, 0, $max_chars) . "...";
  echo json_encode([
    'ok'=>true,
    'reply'=>$reply,
    'model'=>$json['model'] ?? LLAMA_MODEL_ID,
    'temp'=>$temp,
    'debug'=>['books'=>$books, 'boosted'=> (bool)$boost_text, 'formality_used'=>$use_formality ? $formality_pct : null]
  ]);
  exit;
}

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

/* Few-shot pairs */
$shots = [];
for ($i = count($recent)-1; $i >= 1 && count($shots) < FEWSHOT_PAIRS; $i--) {
  $a = $recent[$i-1]; $b = $recent[$i];
  if ($a['sender'] !== $my_name && $b['sender'] === $my_name && $a['message'] !== '' && $b['message'] !== '') {
    $shots[] = [$a['message'], $b['message']];
  }
}
$shots = array_reverse($shots);

$systemRules =
"Escreve **exatamente** no estilo do utilizador \"{$my_name}\" (PT-PT).
Objetivo: responder como o {$my_name}, coerente com o histórico recente.
Regras:
- Nada de meta/IA. Nunca digas \"sou um modelo\".
- Mantém o comprimento e o tom aproximados ao histórico.
- Se te pedirem opinião, sê leve e sem inventar factos; faz UMA pergunta curta para contexto.
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
    "Prioridade: **aplicar as técnicas selecionadas de forma notória mas natural**; se houver conflito, mantém a minha voz e o contexto.";
}

$messages = [];
$messages[] = ['role' => 'system', 'content' => $systemRules];

foreach ($shots as [$u, $me]) {
  $messages[] = ['role'=>'user','content'=>$u];
  $messages[] = ['role'=>'assistant','content'=>$me];
}

$TAIL_TURNS_LOCAL = min((int)TAIL_TURNS, 200);
$tail = array_slice($recent, max(0, count($recent) - $TAIL_TURNS_LOCAL));
foreach ($tail as $t) {
  $role = ($t['sender'] === $my_name) ? 'assistant' : 'user';
  if ($t['message'] !== '') $messages[] = ['role'=>$role, 'content'=>$t['message']];
}

/* Finally, the new incoming message */
$messages[] = ['role'=>'user', 'content'=>$received];

$payload = [
  "model"       => LLAMA_MODEL_ID,
  "messages"    => $messages,
  "temperature" => $temp,
  "max_tokens"  => LLAMA_MAX_TOKENS,
  "stream"      => false
];

$ch = curl_init(LLAMA_API_URL);
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 120,
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$response = curl_exec($ch);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno)     { echo json_encode(['error'=>"Erro ao contactar a IA: $error"]); exit; }
if (!$response) { echo json_encode(['error'=>"Resposta vazia da IA (HTTP $httpCode)"]); exit; }

$json  = json_decode($response, true);
$reply = $json['choices'][0]['message']['content'] ?? null;
if (!$reply) { echo json_encode(['error'=>"Resposta inesperada da IA", 'raw'=>$response]); exit; }

$reply = trim($reply);
if ($max_chars > 0 && mb_strlen($reply) > $max_chars) $reply = mb_substr($reply, 0, $max_chars) . "...";

echo json_encode([
  'ok'      => true,
  'reply'   => $reply,
  'model'   => $json['model'] ?? LLAMA_MODEL_ID,
  'temp'    => $temp,
  'debug'   => [
    'books'          => $books,
    'boosted'        => (bool)$boost_text,
    'boost_titles'   => $boost_titles,
    'rules_used'     => array_slice($boost_rules, 0, 10),
    'formality_used' => $use_formality ? $formality_pct : null
  ]
]);
exit;
