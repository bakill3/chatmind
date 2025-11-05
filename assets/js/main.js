$(function () {
  const form       = $("#ai-reply-form");
  const genBtn     = $("#gen-btn");
  const outBox     = $("#ai-reply-box");
  const out        = $("#ai-reply");
  const errBox     = $("#ai-error");
  const suggestionsBox = $("#ai-suggestions");
  const refreshBtn = $("#refresh-btn");
  const cancelBtn  = $("#cancel-btn");
  const approveBtn = $("#approve-btn");
  const thumbUpBtn = $("#thumb-up-btn");
  const thumbDnBtn = $("#thumb-down-btn");
  const voteStatus = $("#vote-status");
  const chat       = $("#chatWin");

  let inflight = false;
  let voting  = false;
  let lastGenMeta = null; // stores last {reply, model, temp, debug...}
  let lastPairHash = null; // set after vote/save, to prevent double-vote spam
  let selectedSuggestionIndex = null;

  function scrollBottom() { if (!chat.length) return; chat.scrollTop(chat[0].scrollHeight); }
  scrollBottom(); setTimeout(scrollBottom, 100); setTimeout(scrollBottom, 350);

  // Temperature UI
  const sld = $("#tempSlider");
  if (sld.length) {
    const pct = $("#tempPct");
    const val = $("#tempVal");
    const hid = $("#temp_pct");

    const DEF_MIN = (typeof window.TEMP_MIN === "number") ? window.TEMP_MIN : 0.40;
    const DEF_MAX = (typeof window.TEMP_MAX === "number") ? window.TEMP_MAX : 1.20;

    function mapPctToTemp(p, tmin, tmax) { return tmin + (tmax - tmin) * (p / 100.0); }
    function up() {
      const p = parseInt(sld.val(), 10);
      const pctVal = Number.isFinite(p) ? p : 0;
      pct.text(pctVal + "%");
      const t = mapPctToTemp(pctVal, DEF_MIN, DEF_MAX);
      if (val.length) val.text( (Number.isFinite(t) ? t : DEF_MIN).toFixed(2) );
      if (hid.length) hid.val(pctVal);
    }
    sld.on("input change", up); up();
  }

  // Formality UI
  const useForm = $("#use_formality");
  const formRow = $("#formalityRow");
  const formSld = $("#formalitySlider");
  const formPct = $("#formalityPct");
  const formLbl = $("#formalityLabel");

  function upForm() { formRow.toggle(useForm.is(":checked")); }
  function upFormSld() {
    const p = parseInt(formSld.val(), 10) || 0;
    formPct.text(p + "%");
    let label = "Casual";
    if (p >= 70) label = "Muito Formal";
    else if (p >= 50) label = "Formal";
    else if (p >= 30) label = "Neutro";
    formLbl.text("(" + label + ")");
  }
  useForm.on("change", upForm); upForm();
  formSld.on("input change", upFormSld); upFormSld();

  // Books legacy inputs (not used; we build dynamic hidden inputs in conversation.php)
  const picks       = $(".book-pick");
  const booksHidden = $("#books");
  const booksCount  = $("#books-count");
  function upBooks() {
    if (!picks.length) return;
    const arr = picks.filter(":checked").map(function () { return $(this).val(); }).get();
    if (booksHidden.length) booksHidden.val(arr.join(","));
    if (booksCount.length)  booksCount.text(arr.length);
  }
  picks.on("change", upBooks); upBooks();

  function collectBooks() {
    return Array.from(document.querySelectorAll('#books-holder input[name="books[]"]'))
      .map(i => i.value);
  }

  function callAI() {
    if (inflight) return;
    inflight = true;

    errBox.hide().text("");
    out.text("A pensar…");
    outBox.show();
    genBtn.prop("disabled", true).text("A gerar…");
    voteStatus.text("");
    lastGenMeta = null;
    lastPairHash = null;

    $.ajax({
      url: "generate.php",
      method: "POST",
      data: form.serialize(),
      dataType: "json",
      timeout: 120000
    })
    .done(function (resp, _status, xhr) {
      if (!resp || typeof resp !== "object") {
        try { resp = JSON.parse(xhr.responseText || "{}"); } catch(e){}
      }
      if (resp && resp.error) {
        throw new Error(resp.error);
      }
      // Prefer structured suggestions array
      selectedSuggestionIndex = null;
      if (resp && Array.isArray(resp.suggestions) && resp.suggestions.length) {
        // render suggestions list
        if (suggestionsBox.length) {
          suggestionsBox.empty();
          resp.suggestions.forEach(function(s, i) {
            const t = (s && s.text) ? s.text : '';
            const tone = s.tone ? '['+s.tone+'] ' : '';
            const why = s.why ? '\n— '+s.why : '';
            const item = $('<div class="ai-sugg-item p-2 mb-2 border rounded" data-idx="'+i+'"></div>');
            const txt = $('<div class="ai-sugg-text"></div>').text(tone + t + why);
            const actions = $('<div class="ai-sugg-actions mt-1"></div>');
            const useBtn = $('<button type="button" class="btn btn-sm btn-outline-primary me-2">Usar</button>');
            const copyBtn = $('<button type="button" class="btn btn-sm btn-outline-secondary">Copiar</button>');
            useBtn.on('click', function(){ selectSuggestion(i); });
            copyBtn.on('click', function(){ copyToClipboard(t); });
            actions.append(useBtn).append(copyBtn);
            item.append(txt).append(actions);
            suggestionsBox.append(item);
          });
          suggestionsBox.show();
        }
        // show the top suggestion in preview area
        const top = resp.suggestions[0];
        const preview = (top && top.text) ? top.text : '';
        out.text(preview || '');
      } else {
        // fallback to old reply_text or reply
        const reply = (resp && resp.reply_text) ? resp.reply_text : (resp && resp.reply ? resp.reply : '');
        out.text(reply || '');
        if (suggestionsBox.length) suggestionsBox.empty().hide();
      }
      lastGenMeta = resp || null;
      // show model and timing info if present
      if (resp && resp.debug) {
        const modelUsed = resp.debug.model_used || resp.model || '';
        const elapsedMs = resp.debug.elapsed_ms || null;
        if ($('#ai-model-info').length === 0) {
          const info = $('<div id="ai-model-info" class="small text-muted mt-1"></div>');
          outBox.append(info);
        }
        let txt = modelUsed ? ('Modelo: ' + modelUsed) : '';
        if (elapsedMs) txt += (txt ? ' · ' : '') + elapsedMs + ' ms';
        $('#ai-model-info').text(txt).show();
      } else {
        $('#ai-model-info').hide();
      }
      // show raw debug reply if provided for troubleshooting
      if (resp && resp.debug && resp.debug.raw_reply) {
        if ($('#ai-raw-debug').length === 0) {
          const dbg = $('<pre id="ai-raw-debug" class="mt-2 p-2 small bg-light text-muted" style="display:none; white-space:pre-wrap; max-height:240px; overflow:auto;border-radius:.5rem;"></pre>');
          outBox.append(dbg);
        }
        $('#ai-raw-debug').text(resp.debug.raw_reply).show();
      } else {
        $('#ai-raw-debug').hide();
      }
      setTimeout(scrollBottom, 100);
    })
    .fail(function (jq, textStatus, errorThrown) {
      const msg = (jq.responseJSON && jq.responseJSON.error)
        ? jq.responseJSON.error
        : (errorThrown || textStatus || "Erro desconhecido");
      outBox.hide();
      errBox.text("Erro: " + msg).show();
      console.error("generate.php fail:", { textStatus, errorThrown, resp: jq.responseText });
    })
    .always(function () {
      inflight = false;
      genBtn.prop("disabled", false).text("Gerar resposta AI");
    });
  }

  function selectSuggestion(idx) {
    selectedSuggestionIndex = idx;
    // highlight selection
    suggestionsBox.find('.ai-sugg-item').removeClass('selected');
    const el = suggestionsBox.find('.ai-sugg-item[data-idx="'+idx+'"]');
    el.addClass('selected');
    // set preview text
    const meta = lastGenMeta || {};
    const s = (meta.suggestions && meta.suggestions[idx]) ? meta.suggestions[idx] : null;
    if (s && s.text) out.text(s.text);
  }

  function copyToClipboard(t) {
    if (!t) return;
    try { navigator.clipboard.writeText(t); } catch (e) {
      // fallback
      const ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
  }

  form.on("submit", function (e) {
    e.preventDefault(); e.stopPropagation();
    const txt = $("#received").val();
    if (!txt || !txt.trim()) return;
    callAI();
  });

  genBtn.on("click", function (e) { e.preventDefault(); e.stopPropagation(); form.trigger("submit"); });
  refreshBtn.on("click", function (e) { e.preventDefault(); callAI(); });
  cancelBtn.on("click", function () { outBox.hide(); out.text(""); voteStatus.text(""); });

  approveBtn.on("click", function (e) {
    e.preventDefault();

    const received  = ($("#received").val() || "").trim();
    // if a suggestion was selected, prefer it
    let replyTxt = (out.text() || "").trim();
    if (selectedSuggestionIndex !== null && lastGenMeta && Array.isArray(lastGenMeta.suggestions) && lastGenMeta.suggestions[selectedSuggestionIndex]) {
      replyTxt = (lastGenMeta.suggestions[selectedSuggestionIndex].text || replyTxt).trim();
    }
    const uploadId  = $("input[name='upload_id']").val();
    const contactNm = $("#contact_name").val() || "";
    const books     = collectBooks();

    if (!replyTxt) { alert("Sem resposta para aprovar."); return; }
    if (!received) { alert("Preenche a mensagem recebida antes de aprovar."); return; }

    const meta = lastGenMeta || {};
    const payload = {
      upload_id:  uploadId,
      contact_name: contactNm,
      received:  received,
      reply:     replyTxt,
      model:     (meta.model || ''),
      temp:      (meta.temp  || '')
    };
    books.forEach((b, i) => payload[`books[${i}]`] = b);

    approveBtn.prop("disabled", true).text("A aprovar…");
    $.post("approve.php", payload)
      .done(function (resp) {
        if (resp && resp.ok) {
          approveBtn.text("Aprovado ✓");
          voteStatus.text("Guardado nos exemplos aprovados.");
          lastPairHash = resp.pair_hash || null;
          setTimeout(() => { approveBtn.text("Aprovar").prop("disabled", false); $("#ai-reply-box").hide(); }, 800);
        } else {
          alert("Falhou ao guardar a aprovação.");
          console.warn(resp);
          approveBtn.text("Aprovar").prop("disabled", false);
        }
      })
      .fail(function (jq) {
        alert("Erro ao guardar aprovação: " + (jq.responseJSON?.error || jq.statusText || "desconhecido"));
        approveBtn.text("Aprovar").prop("disabled", false);
      });
  });

  function vote(dir) {
    if (voting) return;
    const received  = ($("#received").val() || "").trim();
    let replyTxt = (out.text() || "").trim();
    if (selectedSuggestionIndex !== null && lastGenMeta && Array.isArray(lastGenMeta.suggestions) && lastGenMeta.suggestions[selectedSuggestionIndex]) {
      replyTxt = (lastGenMeta.suggestions[selectedSuggestionIndex].text || replyTxt).trim();
    }
    const uploadId  = $("input[name='upload_id']").val();
    const contactNm = $("#contact_name").val() || "";
    const books     = collectBooks();

    if (!replyTxt) { alert("Sem resposta para votar."); return; }
    if (!received) { alert("Preenche a mensagem recebida antes de votar."); return; }

    const meta = lastGenMeta || {};
    const payload = {
      upload_id:  uploadId,
      contact_name: contactNm,
      received:  received,
      reply:     replyTxt,
      model:     (meta.model || ''),
      temp:      (meta.temp  || ''),
      dir:       dir
    };
    if (lastPairHash) payload.pair_hash = lastPairHash;
    books.forEach((b, i) => payload[`books[${i}]`] = b);

    voting = true;
    voteStatus.text("A registar voto…");

    $.post("vote.php", payload)
      .done(function (resp) {
        if (resp && resp.ok) {
          voteStatus.text(dir === "up" ? "👍 Voto registado." : "👎 Voto registado.");
          lastPairHash = resp.pair_hash || lastPairHash || null;
        } else {
          voteStatus.text("Falha a votar.");
          console.warn(resp);
        }
      })
      .fail(function (jq) {
        voteStatus.text("Erro a votar: " + (jq.responseJSON?.error || jq.statusText || "desconhecido"));
      })
      .always(function () { voting = false; });
  }

  thumbUpBtn.on("click", function (e) { e.preventDefault(); vote("up"); });
  thumbDnBtn.on("click", function (e) { e.preventDefault(); vote("down"); });

  // small entrance polish
  $(".card").css({ opacity: 0, transform: "scale(0.985)" })
    .delay(80).animate({ opacity: 1 }, 240)
    .css({ transform: "scale(1)" });

  const pre = $("#preloader");
  if (pre.length) setTimeout(() => pre.addClass("fade-out"), 200);
});
