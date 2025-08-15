  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    window.addEventListener('load', () => {
      const p = document.getElementById('preloader');
      if (!p) return;
      p.classList.add('fade-out');
      setTimeout(()=> p.remove(), 500);
    });

    const toReveal = document.querySelectorAll('.reveal-up, .reveal-top');
    const io = new IntersectionObserver((entries)=>{
      entries.forEach(e=>{
        if(e.isIntersecting){ e.target.classList.add('revealed'); io.unobserve(e.target); }
      });
    },{ threshold: .14 });
    toReveal.forEach(el=> io.observe(el));

    (() => {
      function startEdit(span, id, current){
        if (span.dataset.editing === '1') return;
        span.dataset.editing = '1';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-sm editable-input';
        input.value = current;
        input.maxLength = 255;
        input.style.maxWidth = '420px';

        const ok = document.createElement('button');
        ok.className = 'btn btn-sm btn-success ms-2';
        ok.innerHTML = '<i class="bi bi-check-lg"></i>';
        ok.title = 'Confirmar';

        const cancel = document.createElement('button');
        cancel.className = 'btn btn-sm btn-outline-secondary ms-1';
        cancel.innerHTML = '<i class="bi bi-x-lg"></i>';
        cancel.title = 'Cancelar';

        const wrapper = document.createElement('span');
        wrapper.className = 'inline-rename d-inline-flex align-items-center fade-in';
        wrapper.appendChild(input);
        wrapper.appendChild(ok);
        wrapper.appendChild(cancel);

        const originalText = span.textContent;
        span.style.display = 'none';
        const parent = span.parentElement;
        parent.insertBefore(wrapper, span.nextSibling);

        setTimeout(() => { input.focus(); input.select(); }, 10);

        function cleanup(){
          wrapper.remove();
          span.style.display = '';
          span.dataset.editing = '0';
        }

        cancel.addEventListener('click', cleanup);
        input.addEventListener('keydown', e => {
          if (e.key === 'Escape') cleanup();
          if (e.key === 'Enter') ok.click();
        });

        ok.addEventListener('click', async () => {
          const newTitle = input.value.trim();
          if (!newTitle) return;
          ok.disabled = true; cancel.disabled = true; input.disabled = true;
          ok.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
          try {
            const res = await fetch('rename_chat.php', {
              method: 'POST',
              headers: {'Content-Type':'application/x-www-form-urlencoded'},
              body: new URLSearchParams({ upload_id: id, title: newTitle })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'erro');
            span.textContent = data.title;
            if (span.dataset.ctx === 'conversation') {
              document.title = 'ChatMind – ' + data.title;
            }
            cleanup();
          } catch (err) {
            alert('Erro ao renomear: ' + err.message);
            ok.disabled = cancel.disabled = input.disabled = false;
            ok.innerHTML = '<i class="bi bi-check-lg"></i>';
          }
        });
      }

      document.addEventListener('click', e => {
        const btn = e.target.closest('.edit-title');
        if (!btn) return;
        const id = btn.getAttribute('data-id');
        const current = btn.getAttribute('data-current') || '';
        const span = btn.parentElement.querySelector('.chat-title');
        if (span) startEdit(span, id, current);
      });
    })();

    (() => {
      if (!document.querySelector('.book-pick')) return; // <-- evita interferir com a nova galeria

      const form = document.getElementById('ai-reply-form');
      if (!form) return;

      function syncBooks(){
        const picks = [...document.querySelectorAll('.book-pick:checked')].map(c=>c.value);
        const holder = document.getElementById('books-holder');
        if (!holder) return;
        holder.innerHTML = '';
        picks.forEach(v => {
          const i = document.createElement('input');
          i.type = 'hidden'; i.name = 'books[]'; i.value = v;
          holder.appendChild(i);
        });
        const cnt = document.getElementById('books-count');
        if (cnt) cnt.textContent = String(picks.length);
      }
      document.addEventListener('change', (e)=>{
        if (e.target.classList && e.target.classList.contains('book-pick')) syncBooks();
        if (e.target.id === 'use_formality') {
          const row = document.getElementById('formalityRow');
          if (row) row.style.display = e.target.checked ? '' : 'none';
        }
      });
      syncBooks();
    })();
  </script>
</body>
</html>
