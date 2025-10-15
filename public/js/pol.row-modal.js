// public/js/pol.row-modal.js
(() => {
  const $  = (s, p=document) => p.querySelector(s);
  const $$ = (s, p=document) => Array.from(p.querySelectorAll(s));
  if (!window.API_URL) window.API_URL = '/balp2/api.php';
  const storageKey='balp_token';
  const getToken = ()=>{ try{return localStorage.getItem(storageKey)||'';}catch(e){return '';} };
  const withAuth = (url) => {
    const t = getToken();
    const u = new URL(url, location.origin);
    if (t) u.searchParams.set('token', t);
    u.searchParams.set('_ts', Date.now());
    return u.toString();
  };
  async function apiGet(url){
    const r = await fetch(withAuth(url), { credentials: 'include' });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }
  async function apiPost(url, bodyObj){
    const r = await fetch(withAuth(url), {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'include',
      body: bodyObj ? JSON.stringify(bodyObj) : undefined
    });
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }

  let modal, modalEl, currentId = 0;
  function ensureModal() {
    if (modalEl) return;
    modalEl = $('#polRowModal');
    if (!modalEl) {
      // try to inject if missing
      console.warn('polRowModal not found; injecting skeleton');
      const wrap = document.createElement('div');
      wrap.innerHTML = `<!-- Modal for actions on a single Polotovar row (Edit / Clone / Delete) -->
<div class="modal fade" id="polRowModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-box-seam"></i> Polotovar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
      </div>
      <div class="modal-body">
        <div id="polRowModalBody">
          <div class="placeholder-glow">
            <div class="placeholder col-12" style="height: 2rem;"></div>
            <div class="placeholder col-8"></div>
            <div class="placeholder col-6"></div>
            <div class="placeholder col-4"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <div class="me-auto small text-muted" id="polRowMeta"></div>
        <div class="btn-group">
          <button type="button" class="btn btn-outline-primary" id="polRowEdit"><i class="bi bi-pencil-square"></i> Upravit</button>
          <button type="button" class="btn btn-outline-secondary" id="polRowClone"><i class="bi bi-files"></i> Klonovat</button>
          <button type="button" class="btn btn-outline-danger" id="polRowDelete"><i class="bi bi-trash"></i> Smazat</button>
        </div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zavřít</button>
      </div>
    </div>
  </div>
</div>
`;
      document.body.appendChild(wrap);
      modalEl = $('#polRowModal');
    }
    modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  }
  function renderBody(row) {
    const esc = (s) => (s==null?'':String(s));
    return `
      <div class="row g-3">
        <div class="col-md-3"><div class="form-text">ID</div><div class="fw-semibold">${esc(row.id)}</div></div>
        <div class="col-md-3"><div class="form-text">Číslo</div><div class="fw-semibold">${esc(row.cislo)}</div></div>
        <div class="col-md-6"><div class="form-text">Název</div><div class="fw-semibold">${esc(row.nazev)}</div></div>
        <div class="col-md-2"><div class="form-text">SH</div><div>${esc(row.sh)}</div></div>
        <div class="col-md-2"><div class="form-text">OKP</div><div>${esc(row.okp)}</div></div>
        <div class="col-md-2"><div class="form-text">Olej</div><div>${esc(row.olej)}</div></div>
        <div class="col-md-12"><div class="form-text">Poznámka</div><div>${esc(row.pozn)}</div></div>
      </div>`;
  }
  async function openRowModal(id){
    ensureModal();
    currentId = id;
    $('#polRowModalBody').innerHTML = '<div class="text-muted">Načítám…</div>';
    $('#polRowMeta').textContent = '';
    modal.show();
    try {
      const data = await apiGet('/balp2/api/pol_get.php?id=' + encodeURIComponent(id));
      $('#polRowModalBody').innerHTML = renderBody(data);
      $('#polRowMeta').textContent = 'ID: ' + id;
    } catch (e) {
      $('#polRowModalBody').innerHTML = `<div class="alert alert-danger">Chyba načtení: ${e}</div>`;
    }
  }

  // Wire actions
  document.addEventListener('click', (e) => {
    const tr = e.target.closest && e.target.closest('#pol-table tbody tr');
    if (!tr) return;
    const id = tr.dataset.id || tr.getAttribute('data-id') || tr.querySelector('td')?.textContent.trim();
    if (!id) return;
    // Prefer existing vp modal if user clicked the dedicated button elsewhere
    if (e.target.closest('.no-row-modal')) return;
    openRowModal(id);
  });

  document.addEventListener('click', async (e)=>{
    if (e.target.id === 'polRowEdit') {
      e.preventDefault();
      if (typeof window.openPolEditor === 'function') {
        modal.hide();
        window.openPolEditor(currentId);
      } else {
        alert('Editor není k dispozici v této verzi.');
      }
    }
    if (e.target.id === 'polRowClone') {
      e.preventDefault();
      if (!currentId) return;
      if (!confirm('Opravdu klonovat tento polotovar?')) return;
      try{
        const res = await apiPost('/balp2/api/pol_clone.php?id=' + encodeURIComponent(currentId));
        // reload list if available
        if (typeof window.reloadPolList === 'function') window.reloadPolList();
        alert('Záznam naklonován.');
      }catch(err){ alert('Chyba klonování: ' + err); }
    }
    if (e.target.id === 'polRowDelete') {
      e.preventDefault();
      if (!currentId) return;
      if (!confirm('Opravdu smazat tento polotovar?')) return;
      try{
        const res = await apiPost('/balp2/api/pol_delete.php?id=' + encodeURIComponent(currentId));
        modal.hide();
        if (typeof window.reloadPolList === 'function') window.reloadPolList();
      }catch(err){ alert('Chyba mazání: ' + err); }
    }
  });

  // Export helper
  window.openPolRowModal = openRowModal;
})();