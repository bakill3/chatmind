<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) header('Location: index.php');
if (!isset($_GET['upload_id'])) die("Sem upload_id!");

$upload_id = (int)$_GET['upload_id'];

$stmt = $pdo->prepare("SELECT * FROM uploads WHERE id = ? AND user_id = ?");
$stmt->execute([$upload_id, $_SESSION['user_id']]);
$up = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$up) die("Upload não encontrado!");

$filepath = UPLOADS_DIR . '/' . $up['filename'];
if (!is_file($filepath)) die("Ficheiro TXT não existe!");

// parse WhatsApp TXT
$txt = file_get_contents($filepath);
$re  = '/^\[?(\d{2}\/\d{2}\/\d{2,4}),\s+(\d{2}:\d{2}(?::\d{2})?)\]?\s([^:]+):\s(.+)$/mu';
preg_match_all($re, $txt, $mset, PREG_SET_ORDER);

$messages = [];
$all_names = [];
foreach ($mset as $m) {
    $sender = trim($m[3]);
    $messages[] = [
        'sender'  => $sender,
        'message' => trim($m[4]),
        'time'    => $m[1] . ' ' . $m[2],
    ];
    if ($sender !== '') $all_names[$sender] = true;
}

// discover your name
$stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$reg_name = $stmt->fetchColumn() ?: 'Eu';

function normalize_name($s) {
    if (function_exists('transliterator_transliterate')) {
        $s = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $s);
    } else {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($tmp !== false) $s = $tmp;
    }
    $s = preg_replace('/[^a-z0-9 ]/i', '', $s);
    return strtolower(trim($s));
}
$reg_norm = normalize_name($reg_name);
$my_name = $reg_name; $best = -1;
foreach (array_keys($all_names) as $n) {
    similar_text(normalize_name($n), $reg_norm, $p);
    if ($p > $best) { $best = $p; $my_name = $n; }
}
$_SESSION['chat_my_name'] = $my_name;

// primary "other" — last non-you sender
$other_name = 'a outra pessoa';
for ($i = count($messages)-1; $i >= 0; $i--) {
    if ($messages[$i]['sender'] !== $my_name) { $other_name = $messages[$i]['sender']; break; }
}

$title = $up['title'] ?: pathinfo($up['filename'], PATHINFO_FILENAME);
$has_profile = !empty($up['profile_json']);
$isWA = !empty($up['is_whatsapp']);

$profileSummaryHtml = '';
if ($has_profile) {
    $pj = json_decode($up['profile_json'], true);
    if (is_array($pj)) {
        $lines = [];
        if (!empty($pj['my_name_guess']))       $lines[] = '<li><b>Tu:</b> '.htmlspecialchars($pj['my_name_guess']).'</li>';
        if (!empty($pj['other_primary']))       $lines[] = '<li><b>Outro:</b> '.htmlspecialchars($pj['other_primary']).'</li>';
        if (!empty($pj['stats']['total_messages'])) $lines[] = '<li><b>Mensagens:</b> '.$pj['stats']['total_messages'].'</li>';
        if (!empty($pj['tone']) && is_array($pj['tone'])) $lines[] = '<li><b>Tom:</b> '.htmlspecialchars(implode(', ', $pj['tone'])).'</li>';
        if (!empty($pj['style_notes']))         $lines[] = '<li>'.htmlspecialchars($pj['style_notes']).'</li>';
        if (!empty($pj['conv_summary']))        $lines[] = '<li><b>Resumo:</b> '.htmlspecialchars($pj['conv_summary']).'</li>';
        if ($lines) $profileSummaryHtml = '<ul class="m-0 ps-3">'.implode('', $lines).'</ul>';
    }
}

include 'header.php';
?>
<nav class="navbar app-navbar shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="dashboard.php">ChatMind</a>
    <a class="btn btn-outline-light" href="logout.php">Sair</a>
  </div>
</nav>

<div class="container py-3">
  <div class="card glassmorph shadow">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h4 class="m-0 d-flex align-items-center gap-2 flex-wrap">
          <?php if ($isWA): ?>
            <span class="text-success" title="WhatsApp">
              <i class="bi bi-whatsapp"></i>
            </span>
          <?php endif; ?>
          Conversa:
          <span id="chatTitle" class="chat-title" data-id="<?= $upload_id ?>" data-ctx="conversation">
            <?= htmlspecialchars($title) ?>
          </span>
          <button class="btn btn-sm btn-link p-0 text-decoration-none edit-title"
                  data-id="<?= $upload_id ?>" data-current="<?= htmlspecialchars($title) ?>"
                  title="Renomear" aria-label="Renomear">
            <i class="bi bi-pencil"></i>
          </button>
        </h4>

        <div class="d-flex align-items-center gap-3 flex-wrap">
          <div class="badge bg-light text-dark px-3 py-2 rounded-pill">
            Tu: <b><?= htmlspecialchars($my_name) ?></b>
          </div>

          <?php if ($has_profile): ?>
            <span
              id="profBadge"
              class="badge bg-success-subtle text-success rounded-pill px-3 py-2"
              data-bs-toggle="popover"
              data-bs-html="true"
              data-bs-trigger="hover focus"
              title="Resumo do perfil"
              data-bs-content='<?= $profileSummaryHtml ? $profileSummaryHtml : "Perfil disponível." ?>'
              style="cursor: help;"
            >
              Perfil carregado
            </span>
          <?php else: ?>
            <span id="profBadge"
                  class="badge text-bg-secondary d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill"
                  data-state="loading"
                  style="cursor: progress;">
              <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
              <span class="label">A analisar perfil…</span>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div id="chatWin" class="chat-window mb-4">
        <?php foreach ($messages as $msg):
          $mine = ($msg['sender'] === $my_name); ?>
          <div class="msg-row <?= $mine ? 'me' : 'other' ?>">
            <div class="bubble">
              <?php if (!$mine): ?><div class="sender"><?= htmlspecialchars($msg['sender']) ?></div><?php endif; ?>
              <div class="body"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
              <div class="time"><?= htmlspecialchars($msg['time']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <h5 class="mb-2">Nova mensagem recebida</h5>
      <form id="ai-reply-form" method="POST" action="generate.php" class="composer">
        <div class="row g-3 align-items-center">
          <div class="col-12 col-lg-7">
            <input type="text" name="received" id="received" class="form-control"
              placeholder="Cola aqui a mensagem recebida de <?= htmlspecialchars($other_name) ?>..." required>
          </div>

          <div class="col-12 col-lg-5">
            <label class="form-label mb-1 small text-muted">Criatividade</label>
            <div class="d-flex align-items-center gap-2">
              <input type="range" min="0" max="100" value="60" id="tempSlider" class="form-range flex-grow-1">
              <div style="min-width:120px">
                <span id="tempPct" class="fw-bold">60%</span>
                <span class="text-muted small">(<span id="tempVal">0.98</span>)</span>
              </div>
            </div>
            <input type="hidden" name="temp_pct" id="temp_pct" value="60">
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-12 col-lg-7 d-flex align-items-center gap-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="use_formality" name="use_formality">
              <label class="form-check-label" for="use_formality">Controlar formalidade</label>
            </div>

            <div id="formalityRow" class="flex-grow-1" style="display:none;">
              <label class="form-label mb-1 small text-muted">Formalidade</label>
              <div class="d-flex align-items-center gap-2">
                <input type="range" min="0" max="100" value="45" id="formalitySlider" name="formality_pct" class="form-range flex-grow-1">
                <div style="min-width:160px">
                  <span id="formalityPct" class="fw-bold">45%</span>
                  <span class="text-muted small" id="formalityLabel">(Casual)</span>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-5">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" value="1" id="allow_swear" name="allow_swear" checked>
              <label class="form-check-label" for="allow_swear">Permitir palavrões (se existirem no meu histórico)</label>
            </div>
          </div>
        </div>

        <div class="d-flex align-items-center gap-2 mt-2">
          <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#enhanceModal">
            Enhance Personality <span class="text-muted small">(livros)</span>
          </button>
          <span class="small text-muted">Selecionados: <b id="books-count">0</b></span>
        </div>

        <input type="hidden" name="upload_id" value="<?= $upload_id ?>">
        <div id="books-holder"></div>

        <div class="d-flex align-items-center gap-2 mt-3">
          <button class="btn btn-primary" id="gen-btn" type="button">Gerar resposta AI</button>
          <button class="btn btn-outline-secondary" id="clear-btn" type="button">Limpar</button>
        </div>
      </form>

      <div id="ai-reply-box" class="mt-3" style="display:none;">
        <label class="fw-semibold">Resposta sugerida:</label>
        <div class="ai-reply alert alert-info" id="ai-reply" style="white-space:pre-wrap"></div>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-success" id="approve-btn" type="button">Aprovar</button>
          <button class="btn btn-secondary" id="refresh-btn" type="button">Refresh</button>
          <button class="btn btn-danger" id="cancel-btn" type="button">X</button>
        </div>
      </div>

      <div id="ai-error" class="alert alert-danger mt-3" style="display:none;"></div>
    </div>
  </div>
</div>

<div class="modal fade" id="enhanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content glassmorph">
      <div class="modal-header">
        <h5 class="modal-title">Enhance Personality — escolher livros</h5>
        <button type="button" class="btn btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body p-0">
        <div id="bookGallery"></div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <div class="small text-muted">
          Selecionados: <b id="books-count-modal">0</b>
        </div>
        <button class="btn btn-primary" data-bs-dismiss="modal">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const sld = document.getElementById('tempSlider');
  const pct = document.getElementById('tempPct');
  const val = document.getElementById('tempVal');
  const hid = document.getElementById('temp_pct');
  const clearBtn = document.getElementById('clear-btn');
  const chat = document.getElementById('chatWin');

  const formSld = document.getElementById('formalitySlider');
  const formPct = document.getElementById('formalityPct');
  const formLbl = document.getElementById('formalityLabel');
  const useFormality = document.getElementById('use_formality');
  const formalityRow = document.getElementById('formalityRow');

  function mapPctToTemp(p){
    const tMin = <?= json_encode(TEMP_MIN) ?>;
    const tMax = <?= json_encode(TEMP_MAX) ?>;
    return (tMin + (tMax - tMin) * (p/100));
  }
  function updateTempUI(){
    const p = parseInt(sld.value || '0',10);
    pct.textContent = p + '%';
    val.textContent = mapPctToTemp(p).toFixed(2);
    hid.value = p;
  }
  if (sld) { sld.addEventListener('input', updateTempUI); updateTempUI(); }

  function updateFormalityUI(){
    const p = parseInt(formSld.value || '0',10);
    formPct.textContent = p + '%';
    let label = 'Casual';
    if (p >= 70) label = 'Muito Formal';
    else if (p >= 50) label = 'Formal';
    else if (p >= 30) label = 'Neutro';
    formLbl.textContent = '(' + label + ')';
  }
  if (formSld) { formSld.addEventListener('input', updateFormalityUI); updateFormalityUI(); }
  if (useFormality && formalityRow) {
    useFormality.addEventListener('change', () => {
      formalityRow.style.display = useFormality.checked ? '' : 'none';
    });
  }

  function scrollBottom(){ if (chat) chat.scrollTop = chat.scrollHeight; }
  window.addEventListener('load', scrollBottom);
  setTimeout(scrollBottom, 120);
  setTimeout(scrollBottom, 360);

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      const rec = document.getElementById('received');
      if (rec) rec.value = '';
    });
  }

  const popovers = document.querySelectorAll('[data-bs-toggle="popover"]');
  if (window.bootstrap && popovers.length) {
    [...popovers].forEach(el => new bootstrap.Popover(el));
  }

  <?php if (!$has_profile): ?>
  fetch('build_profile.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ upload_id: '<?= $upload_id ?>' })
  }).then(r=>r.json()).then(data=>{
    const b = document.getElementById('profBadge');
    if (!b) return;
    if (data.ok) {
      b.className = 'badge bg-success-subtle text-success rounded-pill px-3 py-2';
      b.textContent = 'Perfil carregado';
      b.style.cursor = 'help';
      if (data.summary) {
        b.setAttribute('data-bs-toggle','popover');
        b.setAttribute('data-bs-html','true');
        b.setAttribute('data-bs-trigger','hover focus');
        b.setAttribute('title','Resumo do perfil');
        b.setAttribute('data-bs-content', data.summary);
        if (window.bootstrap) new bootstrap.Popover(b);
      }
    } else {
      b.className = 'badge bg-danger-subtle text-danger rounded-pill px-3 py-2';
      b.textContent = 'Perfil falhou';
      b.style.cursor = 'not-allowed';
      console.warn(data);
    }
  }).catch(err=>{
    const b = document.getElementById('profBadge');
    if (b) {
      b.className = 'badge bg-danger-subtle text-danger rounded-pill px-3 py-2';
      b.textContent = 'Perfil falhou';
      b.style.cursor = 'not-allowed';
    }
    console.error(err);
  });
  <?php endif; ?>

  const bookGallery = document.getElementById('bookGallery');
  const booksHolder = document.getElementById('books-holder');
  const booksCountEl = document.getElementById('books-count');
  const booksCountEl2 = document.getElementById('books-count-modal');

  const BOOKS_DB = [
    { key: 'manip', title: 'Manipulação & Estratégia', items: [
      { id:'48_laws', t:'The 48 Laws of Power', a:'Robert Greene', isbn:'9780140280197' },
      { id:'art_of_war', t:'The Art of War', a:'Sun Tzu', isbn:'9781590302255' },
      { id:'prince', t:'The Prince', a:'Niccolò Machiavelli', isbn:'9780140449150' },
      { id:'33_strat_war', t:'The 33 Strategies of War', a:'Robert Greene', isbn:'9780143112782' },
      { id:'influence', t:'Influence', a:'Robert Cialdini', isbn:'9780061241895' },
    ]},
    { key: 'filo', title: 'Filosofia', items: [
      { id:'meditations', t:'Meditations', a:'Marcus Aurelius', isbn:'9780140449334' },
      { id:'republic', t:'The Republic', a:'Plato', isbn:'9780140455113' },
      { id:'beyond_good_evil', t:'Beyond Good and Evil', a:'Friedrich Nietzsche', isbn:'9780140449235' },
      { id:'nicomachean', t:'Nicomachean Ethics', a:'Aristotle', isbn:'9780140449495' },
      { id:'letters_stoic', t:'Letters from a Stoic', a:'Seneca', isbn:'9780140442106' },
    ]},
    { key: 'bizneg', title: 'Business & Negociação', items: [
      { id:'never_split', t:'Never Split the Difference', a:'Chris Voss', isbn:'9780062407801' },
      { id:'blue_ocean', t:'Blue Ocean Strategy', a:'Kim & Mauborgne', isbn:'9781591396192' },
      { id:'getting_to_yes', t:'Getting to Yes', a:'Fisher, Ury & Patton', isbn:'9780143118756' },
      { id:'good_to_great', t:'Good to Great', a:'Jim Collins', isbn:'9780066620992' },
      { id:'lean_startup', t:'The Lean Startup', a:'Eric Ries', isbn:'9780307887894' },
    ]},
    { key: 'social', title: 'Social / Dating', items: [
      { id:'models', t:'Models', a:'Mark Manson', isbn:'9781463750350' },
      { id:'game', t:'The Game', a:'Neil Strauss', isbn:'9780060554736' },
      { id:'attached', t:'Attached', a:'Levine & Heller', isbn:'9781585429134' },
      { id:'win_friends', t:'How to Win Friends & Influence People', a:'Dale Carnegie', isbn:'9780671027032' },
      { id:'art_of_seduction', t:'The Art of Seduction', a:'Robert Greene', isbn:'9780142001196' },
    ]},
    { key: 'self', title: 'Self-Improvement', items: [
      { id:'atomic_habits', t:'Atomic Habits', a:'James Clear', isbn:'9780735211292' },
      { id:'deep_work', t:'Deep Work', a:'Cal Newport', isbn:'9781455586691' },
      { id:'mindset', t:'Mindset', a:'Carol Dweck', isbn:'9780345472328' },
      { id:'cant_hurt_me', t:'Can’t Hurt Me', a:'David Goggins', isbn:'9781544512273' },
      { id:'subtle_art', t:'The Subtle Art of Not Giving a F*ck', a:'Mark Manson', isbn:'9780062457714' },
    ]},
  ];

  const coverURL = (isbn, title) => `https://covers.openlibrary.org/b/isbn/${isbn}-M.jpg?default=false`;

  function buildSection(sec) {
    const wrap = document.createElement('section');
    wrap.className = 'book-sec';
    const head = document.createElement('div');
    head.className = 'sec-header';
    head.innerHTML = `<div class="sec-title">${sec.title}</div><div class="sec-count" data-cat="${sec.key}">0 selecionados</div>`;
    const hr = document.createElement('hr'); hr.className = 'sec-hr';
    const row = document.createElement('div'); row.className = 'book-row';
    row.innerHTML = `<button type="button" class="scroll-btn scroll-left" aria-label="esquerda"><i class="bi bi-chevron-left"></i></button>
                     <div class="book-scroller" data-cat="${sec.key}"></div>
                     <button type="button" class="scroll-btn scroll-right" aria-label="direita"><i class="bi bi-chevron-right"></i></button>`;
    const scroller = row.querySelector('.book-scroller');

    sec.items.forEach(it => {
      const card = document.createElement('div');
      card.className = 'book-card'; card.tabIndex = 0;
      card.dataset.id = it.id; card.dataset.cat = sec.key;
      const img = document.createElement('img');
      img.className = 'cover'; img.alt = it.t; img.src = coverURL(it.isbn, it.t);
      img.onerror = function(){ if (!this.dataset.err){ this.dataset.err=1; this.src = `https://placehold.co/300x450?text=${encodeURIComponent(it.t)}`; } };
      const check = document.createElement('div'); check.className = 'check'; check.innerHTML = '✔';
      const meta = document.createElement('div'); meta.className = 'card-meta';
      meta.innerHTML = `<div class="card-title" title="${it.t}">${it.t}</div><div class="card-author">${it.a}</div>`;
      card.appendChild(img); card.appendChild(check); card.appendChild(meta); scroller.appendChild(card);
    });

    row.querySelector('.scroll-left').addEventListener('click', () => scroller.scrollBy({ left: -Math.min(800, scroller.clientWidth), behavior: 'smooth' }));
    row.querySelector('.scroll-right').addEventListener('click', () => scroller.scrollBy({ left:  Math.min(800, scroller.clientWidth), behavior: 'smooth' }));

    wrap.appendChild(head); wrap.appendChild(hr); wrap.appendChild(row);
    return wrap;
  }

  function renderBooks() {
    if (!bookGallery) return;
    bookGallery.innerHTML = '';
    BOOKS_DB.forEach(sec => bookGallery.appendChild(buildSection(sec)));
  }

  function syncBooks() {
    if (!bookGallery) return;
    const selected = bookGallery.querySelectorAll('.book-card.selected');
    const nSel = selected.length;
    const b1 = document.getElementById('books-count'); if (b1) b1.textContent = nSel.toString();
    const b2 = document.getElementById('books-count-modal'); if (b2) b2.textContent = nSel.toString();

    BOOKS_DB.forEach(sec => {
      const n = bookGallery.querySelectorAll(`.book-card.selected[data-cat="${sec.key}"]`).length;
      const badge = bookGallery.querySelector(`.sec-count[data-cat="${sec.key}"]`);
      if (badge) badge.textContent = `${n} selecionados`;
    });

    const holder = document.getElementById('books-holder');
    if (holder) {
      holder.innerHTML = '';
      selected.forEach(card => {
        const el = document.createElement('input');
        el.type = 'hidden'; el.name = 'books[]'; el.value = card.dataset.id;
        holder.appendChild(el);
      });
    }
  }

    function toggleCard(card) { if (card){ card.classList.toggle('selected'); syncBooks(); } }

  renderBooks();
  if (bookGallery) {
    bookGallery.addEventListener('click', (e) => { const card = e.target.closest('.book-card'); if (card) toggleCard(card); });
    bookGallery.addEventListener('keydown', (e) => {
      if (e.key === ' ' || e.key === 'Enter') { const card = e.target.closest('.book-card'); if (card) { e.preventDefault(); toggleCard(card); } }
    });
  }
  syncBooks();

})();
</script>

<?php include 'footer.php'; ?>
