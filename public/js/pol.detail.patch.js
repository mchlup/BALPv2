(() => {
  // helpery z app.js – jen bezpečné reference
  const $  = (s, p=document) => p.querySelector(s);
  const $$ = (s, p=document) => Array.from(p.querySelectorAll(s));
  const getToken = () => { try { return localStorage.getItem('balp_token') || ''; } catch(e){ return ''; } };
  const apiHeaders = () => {
    const h = {'Content-Type':'application/json'};
    const t = getToken(); if (t) h['Authorization'] = 'Bearer ' + t;
    return h;
  };
  async function apiFetch(url, opts={}) {
    const full = url.includes('?') ? `${url}&_ts=${Date.now()}` : `${url}?_ts=${Date.now()}`;
    const t = getToken();
    const withTokenUrl = t ? `${full}&token=${encodeURIComponent(t)}` : full;
    const resp = await fetch(withTokenUrl, {
      method: (opts.method || 'GET'),
      headers: {...apiHeaders(), ...(opts.headers||{})},
      body: opts.body,
      credentials: 'include'
    });
    const text = await resp.text();
    let data = null; try { data = JSON.parse(text); } catch {}
    if (!resp.ok) {
      const msg = data?.error || data?.message || text || `HTTP ${resp.status}`;
      throw new Error(msg);
    }
    return data ?? text;
  }

  // Otevření modalu po kliku na řádek
  function bindPolRowClicks() {
    const tbody = document.querySelector('#pol-table tbody');
    if (!tbody) return;
    tbody.addEventListener('click', async (e) => {
      const tr = e.target.closest('tr');
      if (!tr) return;
      const id = tr.getAttribute('data-id') || tr.firstElementChild?.textContent?.trim();
      if (!id) return;
      try {
        const data = await apiFetch('/balp2/api/pol_get.php?id=' + encodeURIComponent(id));
        fillPolForm(data.item || {});
        const m = new bootstrap.Modal(document.getElementById('polModal')); m.show();
      } catch (err) {
        alert('Načtení polotovaru selhalo: ' + err.message);
      }
    });
  }

  // Vyplnění / čtení formuláře
  function fillPolForm(r) {
    $('#pol-id').value = r.id ?? '';
    $('#pol-cislo').value = r.cislo ?? '';
    $('#pol-nazev').value = r.nazev ?? '';
    $('#pol-sh').value = r.sh ?? '';
    $('#pol-sus_sh').value = r.sus_sh ?? '';
    $('#pol-sus_hmot').value = r.sus_hmot ?? '';
    $('#pol-sus_obj').value = r.sus_obj ?? '';
    $('#pol-okp').value = r.okp ?? '';
    $('#pol-kvn').value = r.kvn ?? '';
    $('#pol-olej').value = r.olej ?? '';
    $('#pol-dt_akt_sloz').value = r.dt_akt_sloz ?? '';
    $('#pol-dtod').value = r.dtod ?? '';
    $('#pol-dtdo').value = r.dtdo ?? '';
    $('#pol-pozn').value = r.pozn ?? '';
  }
  function readPolForm() {
    return {
      id: $('#pol-id').value ? Number($('#pol-id').value) : null,
      cislo: $('#pol-cislo').value.trim() || null,
      nazev: $('#pol-nazev').value.trim(),
      sh: $('#pol-sh').value.trim() || null,
      sus_sh: $('#pol-sus_sh').value.trim() || null,
      sus_hmot: $('#pol-sus_hmot').value.trim() || null,
      sus_obj: $('#pol-sus_obj').value.trim() || null,
      okp: $('#pol-okp').value.trim() || null,
      kvn: $('#pol-kvn').value.trim() || null,
      olej: $('#pol-olej').value.trim() || null,
      dt_akt_sloz: $('#pol-dt_akt_sloz').value.trim() || null,
      dtod: $('#pol-dtod').value.trim() || null,
      dtdo: $('#pol-dtdo').value.trim() || null,
      pozn: $('#pol-pozn').value.trim() || null,
    };
  }

  async function refreshPolListAfterChange() {
    // zkus znovu vyvolat loader, který už máš (controller `pol`)
    // bez zásahu do app.js – jen simulace kliknutí na řazení, nebo trigger custom eventu
    const btnNext = document.querySelector('#pol-next');
    // jemný hack: vystřelíme custom event, pokud ho controller poslouchá; jinak dáme reload přes fintu
    if (window.dispatchEvent) {
      window.dispatchEvent(new CustomEvent('balp-pol-refresh'));
    }
    // fallback – změň limit tam a zpět, aby se znovu načetlo (není-li event)
    if (btnNext) {
      const sel = document.getElementById('pol-limit');
      if (sel) {
        const prev = sel.value;
        sel.value = prev === '50' ? '25' : '50';
        sel.dispatchEvent(new Event('change'));
        setTimeout(() => { sel.value = prev; sel.dispatchEvent(new Event('change')); }, 50);
      }
    }
  }

  // Akce tlačítek v modalu
  function bindPolModalActions() {
    $('#pol-save')?.addEventListener('click', async () => {
      try {
        const body = readPolForm();
        if (!body.nazev) { alert('Vyplňte název.'); return; }
        const res = await apiFetch('/balp2/api/pol_upsert.php', { method:'POST', body: JSON.stringify(body) });
        // po uložení refresh listu
        await refreshPolListAfterChange();
        bootstrap.Modal.getInstance(document.getElementById('polModal'))?.hide();
      } catch (err) {
        alert('Uložení selhalo: ' + err.message);
      }
    });
    $('#pol-delete')?.addEventListener('click', async () => {
      const id = $('#pol-id').value;
      if (!id) return;
      if (!confirm('Opravdu smazat tento polotovar?')) return;
      try {
        await apiFetch('/balp2/api/pol_delete.php?id=' + encodeURIComponent(id), { method:'POST' });
        await refreshPolListAfterChange();
        bootstrap.Modal.getInstance(document.getElementById('polModal'))?.hide();
      } catch (err) {
        alert('Smazání selhalo: ' + err.message);
      }
    });
    $('#pol-clone')?.addEventListener('click', async () => {
      const id = $('#pol-id').value;
      if (!id) return;
      try {
        const res = await apiFetch('/balp2/api/pol_clone.php?id=' + encodeURIComponent(id), { method:'POST' });
        await refreshPolListAfterChange();
        // načti novou kopii do formuláře pro případné další úpravy
        if (res?.id) {
          const data = await apiFetch('/balp2/api/pol_get.php?id=' + encodeURIComponent(res.id));
          fillPolForm(data.item || {});
        }
      } catch (err) {
        alert('Klonování selhalo: ' + err.message);
      }
    });

    // volitelně – vytvoření nového záznamu zvenku:
    window.balpPolNew = () => { fillPolForm({}); new bootstrap.Modal(document.getElementById('polModal')).show(); };
  }

  // Tabulka: doplň data-id a bindni kliky po každém renderu
  function observePolTableRenders() {
    const tbody = document.querySelector('#pol-table tbody');
    if (!tbody) return;
    const obs = new MutationObserver(() => {
      // doplnit data-id z 1. buňky
      Array.from(tbody.querySelectorAll('tr')).forEach(tr => {
        if (!tr.getAttribute('data-id')) {
          const id = tr.firstElementChild?.textContent?.trim();
          if (id) tr.setAttribute('data-id', id);
        }
      });
    });
    obs.observe(tbody, {childList:true});
  }

  document.addEventListener('DOMContentLoaded', () => {
    bindPolRowClicks();
    bindPolModalActions();
    observePolTableRenders();
    const newBtn = document.getElementById('pol-new-btn');
  if (newBtn && typeof window.balpPolNew === 'function') {
    newBtn.addEventListener('click', () => window.balpPolNew());
  }
  });
})();

