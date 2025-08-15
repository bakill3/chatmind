$(function () {
  const form       = $("#ai-reply-form");
  const genBtn     = $("#gen-btn");
  const outBox     = $("#ai-reply-box");
  const out        = $("#ai-reply");
  const errBox     = $("#ai-error");
  const refreshBtn = $("#refresh-btn");
  const cancelBtn  = $("#cancel-btn");
  const approveBtn = $("#approve-btn");
  const chat       = $("#chatWin");

  function scrollBottom() { if (!chat.length) return; chat.scrollTop(chat[0].scrollHeight); }
  scrollBottom(); setTimeout(scrollBottom, 100); setTimeout(scrollBottom, 350);

  const sld = $("#tempSlider");
  if (sld.length) {
    const pct = $("#tempPct");
    const val = $("#tempVal");
    const hid = $("#temp_pct");

    const attrMin = parseFloat(sld.data("tmin"));
    const attrMax = parseFloat(sld.data("tmax"));
    const DEF_MIN = (typeof window.TEMP_MIN === "number") ? window.TEMP_MIN : 0.10;
    const DEF_MAX = (typeof window.TEMP_MAX === "number") ? window.TEMP_MAX : 1.30;
    const TMIN = Number.isFinite(attrMin) ? attrMin : DEF_MIN;
    const TMAX = Number.isFinite(attrMax) ? attrMax : DEF_MAX;

    function mapPctToTemp(p, tmin, tmax) { return tmin + (tmax - tmin) * (p / 100.0); }
    function up() {
      const p = parseInt(sld.val(), 10); // can be NaN
      const pctVal = Number.isFinite(p) ? p : 0;
      pct.text(pctVal + "%");
      const t = mapPctToTemp(pctVal, TMIN, TMAX);
      if (val.length) val.text( (Number.isFinite(t) ? t : TMIN).toFixed(2) );
      if (hid.length) hid.val(pctVal);
    }
    sld.on("input change", up); up();
  }

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

  let inflight = false;

  function callAI() {
    if (inflight) return;
    inflight = true;

    errBox.hide().text("");
    out.text("A pensar…");
    outBox.show();
    genBtn.prop("disabled", true).text("A gerar…");

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
      out.text(resp && resp.reply ? resp.reply : "(sem conteúdo)");
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

  form.on("submit", function (e) {
    // Prevent default *first*, then validate
    e.preventDefault();
    e.stopPropagation();
    const txt = $("#received").val();
    if (!txt || !txt.trim()) return;
    callAI();
  });

  genBtn.on("click", function (e) {
    e.preventDefault();
    e.stopPropagation();
    form.trigger("submit");
  });

  refreshBtn.on("click", function (e) { e.preventDefault(); callAI(); });
  cancelBtn.on("click", function () { outBox.hide(); out.text(""); });
  approveBtn.on("click", function () { alert("Aprovado. (Implementar persistência quando quiseres.)"); });
  $("#clear-btn").on("click", function () { $("#received").val(""); });

  const profBadge = $("#profBadge");
  if (profBadge.length && profBadge.data("needProfile") === "1") {
    profBadge.addClass("is-loading");
    $.post("build_profile.php", { upload_id: profBadge.data("uploadId") })
      .done(function (resp) {
        if (resp && (resp.ok || resp.success)) {
          profBadge
            .removeClass("is-loading text-warning bg-warning-subtle")
            .addClass("text-success bg-success-subtle")
            .text("Perfil carregado");
        } else {
          profBadge
            .removeClass("is-loading text-warning bg-warning-subtle")
            .addClass("text-danger bg-danger-subtle")
            .text("Perfil falhou");
          console.warn("build_profile:", resp);
        }
      })
      .fail(function () {
        profBadge
          .removeClass("is-loading text-warning bg-warning-subtle")
          .addClass("text-danger bg-danger-subtle")
          .text("Perfil falhou");
      });
  }

  $(".card").css({ opacity: 0, transform: "scale(0.985)" })
    .delay(80).animate({ opacity: 1 }, 240)
    .css({ transform: "scale(1)" });

  const pre = $("#preloader");
  if (pre.length) setTimeout(() => pre.addClass("fade-out"), 200);
});
