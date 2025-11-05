<?php
require 'config.php';
$loggedIn = isset($_SESSION['user_id']);
include 'header.php';
?>
<link rel="preload" href="assets/css/home.css" as="style" />
<link rel="stylesheet" href="assets/css/home.css">

<main id="home" class="home" aria-label="ChatMind landing">
  <!-- Full-page background (WebGL + layers) -->
  <div class="bg-layers" aria-hidden="true">
    <canvas id="fx"></canvas>
    <div class="gradient-glow parallax" data-speed="0.12"></div>
    <div class="grain"></div>
  </div>

  <!-- HERO -->
  <section class="hero container" aria-labelledby="hero-title">
    <div class="hero-left">
      <div class="wordmark" aria-label="ChatMind">ChatMind</div>
      <p class="eyebrow">Conversational Intelligence</p>
      <h1 id="hero-title" class="title">
        Make every reply <span class="accent">smarter</span> than the last.
      </h1>
      <p class="sub">
        ChatMind imports, analyzes, and <b>learns</b> from your chats. Approvals and quick <b>thumbs</b> feedback
        become examples the AI reuses — so replies on <b>Slack</b>, <b>Teams</b>, <b>WhatsApp</b>, or even
        <b>Tinder</b> sound like <i>you</i>: your tone, your intent, your style.
      </p>
      <div class="cta-row">
        <?php if ($loggedIn): ?>
          <a class="btn btn-primary btn-lg magnet" href="dashboard.php">Open Dashboard</a>
        <?php else: ?>
          <a class="btn btn-primary btn-lg magnet" href="register.php">Start Free</a>
          <a class="btn btn-ghost btn-lg" href="login.php">Sign in</a>
        <?php endif; ?>
      </div>
      <ul class="meta-points muted" role="list">
        <li>Learning over time (approved replies + 👍/👎)</li>
        <li>Folders shape tone (Work · Friends · Social · Other)</li>
        <li>Per-contact profiles & safe cross-chat reuse</li>
      </ul>
    </div>

    <div class="hero-right">
      <div class="stack" data-tilt>
        <!-- Live example (polished, consistent sizes) -->
        <section class="card demo shadow-1" aria-label="Live example">
          <header class="demo-head">
            <div class="win-dots" aria-hidden="true"><i></i><i></i><i></i></div>
            <span class="label">Live example · Learns from approvals</span>
          </header>

          <div class="demo-body">
            <div class="bubble-row">
              <div class="avatar you"></div>
              <div class="bubble you text-white">“Can you send the proposal tomorrow?”</div>
            </div>

            <div class="bubble-row">
              <div class="avatar ai"></div>
              <div class="bubble ai text-white">
                <span class="badge">Suggested</span>
                Absolutely — I’ll send it by 3pm tomorrow. Do you prefer PDF or DOCX?
                <div class="actions" role="group" aria-label="Feedback">
                  <button class="chip approve" aria-label="Approve reply">Approve</button>
                  <button class="chip" aria-label="Thumbs up">👍</button>
                  <button class="chip" aria-label="Thumbs down">👎</button>
                </div>
              </div>
            </div>

            <div class="meta muted">Approved · Saved as a reusable example for <b>Work</b></div>
          </div>
        </section>

        <!-- Score card (tight rows) -->
        <aside class="card stat shadow-2" aria-label="Selection scoring">
          <dl class="kv">
            <div><dt>Folder</dt><dd>Work</dd></div>
            <div><dt>Tone</dt><dd>Formal</dd></div>
            <div><dt>Approved</dt><dd>12</dd></div>
            <div><dt>👍</dt><dd>34</dd></div>
          </dl>
          <div class="score">
            <div class="score-head"><span>Example score</span><span>82%</span></div>
            <div class="bar"><i style="width:82%"></i></div>
          </div>
          <p class="hint muted">Ranked by folder match, tone tags, and recency.</p>
        </aside>
      </div>
    </div>
  </section>

  <!-- WHO IT’S FOR -->
  <section class="personas container" aria-labelledby="personas-title">
    <h2 id="personas-title">Made for real conversations</h2>
    <p class="muted">Work, friends, dating — ChatMind adapts to your context and voice.</p>
    <div class="persona-grid">
      <div class="persona" data-reveal>
        <h3>Work & Teams</h3>
        <p>Reply faster on <b>Slack</b>, <b>Teams</b>, and email — concise, clear, professional.</p>
        <div class="dials">
          <div class="dial"><span>Formality</span><i style="--v:82%"></i></div>
          <div class="dial"><span>Directness</span><i style="--v:72%"></i></div>
          <div class="dial"><span>Emoji</span><i style="--v:12%"></i></div>
        </div>
      </div>
      <div class="persona" data-reveal>
        <h3>Friends & Family</h3>
        <p>Keep group chats flowing on <b>WhatsApp</b> or <b>Messenger</b> — warm tone, inside jokes intact.</p>
        <div class="dials">
          <div class="dial"><span>Warmth</span><i style="--v:86%"></i></div>
          <div class="dial"><span>Length</span><i style="--v:56%"></i></div>
          <div class="dial"><span>Emoji</span><i style="--v:64%"></i></div>
        </div>
      </div>
      <div class="persona" data-reveal>
        <h3>Dating & Social</h3>
        <p>Spark better openers on <b>Tinder</b>, <b>Hinge</b>, and <b>IG DMs</b> — flirty, playful, still you.</p>
        <div class="dials">
          <div class="dial"><span>Playful</span><i style="--v:78%"></i></div>
          <div class="dial"><span>Witty</span><i style="--v:70%"></i></div>
          <div class="dial"><span>Bold</span><i style="--v:48%"></i></div>
        </div>
      </div>
    </div>
  </section>

  <!-- DIFFERENTIATORS -->
  <section id="features" class="features container" aria-labelledby="features-title">
    <header class="section-head">
      <h2 id="features-title">Why ChatMind feels different</h2>
      <p class="muted">Not “just generate”. It’s <b>retain</b>, <b>refine</b>, <b>reuse</b>.</p>
    </header>

    <div class="bento" role="list">
      <article class="tile big" data-reveal role="listitem">
        <h3>Learning over time</h3>
        <p>Approvals and 👍/👎 become few-shot examples. Each reply improves the next.</p>
        <ul class="ticks"><li>Dynamic examples</li><li>Feedback weighting</li><li>Recency bias</li></ul>
      </article>
      <article class="tile" data-reveal role="listitem">
        <h3>Folders shape tone</h3>
        <p>Drag chats into Work, Friends, Social, Other. Each folder adds tone & safety rules you can toggle.</p>
      </article>
      <article class="tile" data-reveal role="listitem">
        <h3>Per-contact profiles</h3>
        <p>Tone, topics, and proven replies per person — answers that fit each relationship.</p>
      </article>
      <article class="tile tall" data-reveal role="listitem">
        <h3>Safe reply reuse</h3>
        <p>Offer proven replies across similar chats with names and sensitive bits auto-redacted.</p>
      </article>
      <article class="tile" data-reveal role="listitem">
        <h3>Profile survey</h3>
        <p>Lightweight setup (tone, directness, emoji). It learns from you — edit or pause anytime.</p>
      </article>
    </div>
  </section>

  <!-- HOW IT WORKS -->
  <section id="how" class="how container" aria-labelledby="how-title">
    <div class="how-grid">
      <div class="copy" data-reveal>
        <h2 id="how-title">How it works</h2>
        <ol class="steps">
          <li>Import a conversation (TXT) or open an existing thread</li>
          <li>Generate a suggested reply</li>
          <li><b>Approve</b> or give <b>👍/👎</b> to teach the system</li>
          <li>Next time, it sounds more like you — with the right tone and context</li>
        </ol>
        <div class="cta-row">
          <?php if ($loggedIn): ?>
            <a class="btn btn-primary magnet" href="dashboard.php">Get started</a>
          <?php else: ?>
            <a class="btn btn-primary magnet" href="register.php">Create account</a>
            <a class="btn btn-ghost" href="login.php">Sign in</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="viz" data-reveal>
        <div class="viz-card">
          <div class="row tags"><span class="tag">Intent: schedule</span><span class="tag">Style: concise</span></div>
          <div class="row tags"><span class="tag">Similarity ↑</span><span class="tag">Recency ↑</span></div>
          <div class="row score"><span>Selection score</span><div class="bar"><i style="width:76%"></i></div></div>
          <p class="hint muted">Blends folder match, tone overlap, and recency to pick examples under a safe token budget.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- TECH / REQUIREMENTS -->
  <section id="tech" class="tech container" aria-labelledby="tech-title">
    <h2 id="tech-title">Built for local LLM workflows</h2>
    <p class="muted">
      Runs on your machine with an OpenAI-compatible local backend. TXT imports today; live integrations
      (WhatsApp, Teams, Slack) next. No schema changes required for your current MySQL setup.
    </p>
    <div class="req-grid">
      <div class="req">
        <h4>Requirements</h4>
        <ul>
          <li>PHP 7.4+ (tested on PHP 8+)</li>
          <li>MySQL / MariaDB</li>
          <li>XAMPP or similar stack</li>
          <li>Local LLM server (llama.cpp / llama-cpp-python)</li>
        </ul>
      </div>
      <div class="req">
        <h4>Config</h4>
        <p class="mono">LLAMA_API_URL · LLAMA_MODEL_ID · LLAMA_TEMP in <b>config.php</b></p>
        <p class="muted">Swap models freely; uses a standard chat-completions endpoint.</p>
      </div>
      <div class="req">
        <h4>Do this now</h4>
        <ul>
          <li>Upload TXT exports → analyze & summarize</li>
          <li>Generate replies with creativity/formality</li>
          <li>Approve & rate replies to train your style</li>
        </ul>
      </div>
    </div>
  </section>

  <!-- FAQ -->
  <section id="faq" class="faq container" aria-labelledby="faq-title">
    <h2 id="faq-title">Frequently asked</h2>
    <details>
      <summary>How does learning work?</summary>
      <p>Approvals and 👍/👎 are stored per conversation (and globally). We select and weight them as examples in the next prompt.</p>
    </details>
    <details>
      <summary>Can I disable influence?</summary>
      <p>Yes. Disable folder/profile influence per chat or globally and switch to strict “conversation-only”.</p>
    </details>
    <details>
      <summary>What about privacy?</summary>
      <p>Your data tunes your replies only. You can export or delete profile data. Cross-chat reuse auto-redacts sensitive bits.</p>
    </details>
  </section>

  <!-- CTA -->
  <section class="cta" aria-labelledby="cta-title">
    <div class="container center">
      <h2 id="cta-title">Ready for an AI that grows with you?</h2>
      <p class="muted">Every approval sharpens your future replies.</p>
      <?php if ($loggedIn): ?>
        <a class="btn btn-black btn-lg" href="dashboard.php">Open Dashboard</a>
      <?php else: ?>
        <a class="btn btn-black btn-lg" href="register.php">Try it free</a>
      <?php endif; ?>
    </div>
  </section>
</main>

<!-- Three.js (visible 3D background) -->
<script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
<script defer src="assets/js/home.js"></script>
<?php include 'footer.php'; ?>
