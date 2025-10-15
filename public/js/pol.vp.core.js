// public/js/pol.vp.core.js
// VP Core – datové volání + kompletní modál "Výrobní příkaz" (opravené sloupce, součty, přepočty)
(() => {
  /* -------------------- Auth + API -------------------- */
  function authHeaders() {
    const h = { 'Content-Type': 'application/json' };
    try {
      const t = localStorage.getItem('balp_token');
      if (t) h['Authorization'] = 'Bearer ' + t;
    } catch (e) {}
    return h;
  }
  async function fetchVP(polId, kg) {
    const url = `/balp2/api/pol_vyrobni_prikaz.php?id=${encodeURIComponent(polId)}&mnozstvi_kg=${encodeURIComponent(kg || 1)}`;
    const r = await fetch(url, { headers: authHeaders() });
    if (!r.ok) throw new Error(await r.text());
    return await r.json();
  }

  /* -------------------- Helpery (formáty, tabulka) -------------------- */
  function fmtNum(v) {
    const n = Number(v);
    return Number.isFinite(n) ? n.toFixed(2) : '';
  }
  function fmtDate(s) {
    if (!s) return '';
    return String(s).slice(0, 10); // očekává ISO YYYY-MM-DD...
  }
  function colCountForBody(tbody) {
    const ths = tbody?.closest('table')?.querySelectorAll('thead th');
    return ths && ths.length ? ths.length : 5;
  }
  function renderLoadingRow(tbody, text = 'Načítám…') {
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="${colCountForBody(tbody)}">${text}</td></tr>`;
  }
  // jednotný renderer řádků pro „Suroviny“ i „Polotovary“
  function fillVpTable(tbody, rows) {
    if (!tbody) return;
    tbody.innerHTML = '';
    (rows || []).forEach(r => {
      const susHm  = r.sus_hmot ?? r.sushm ?? r.sus_hm;   // fallbacky názvů
      const susObj = r.sus_obj  ?? r.susobj;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r.techpor ?? ''}</td>
        <td>${r.cislo ?? ''}</td>
        <td>${r.nazev ?? ''}</td>
        <td>${fmtDate(r.platnost_od)}</td>
        <td>${fmtDate(r.platnost_do)}</td>
        <td class="text-end">${fmtNum(r.sh)}</td>
        <td class="text-end">${fmtNum(susHm)}</td>
        <td class="text-end">${fmtNum(susObj)}</td>
        <td class="text-end">${fmtNum(r.gkg)}</td>
        <td class="text-end">${fmtNum(r.navazit_g)}</td>
      `;
      tbody.appendChild(tr);
    });
  }
  function appendVpSumRow(tbody, sumValue, label = 'Celkem') {
    if (!tbody) return;
    const span = colCountForBody(tbody) - 1; // vše kromě posledního sloupce
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td colspan="${span}" class="text-end"><strong>${label}</strong></td>
      <td class="text-end"><strong>${fmtNum(sumValue)}</strong></td>
    `;
    tbody.appendChild(tr);
  }

  /* -------------------- Modál: otevření + naplnění -------------------- */
  // Přidej NAD openVpModal (nebo kamkoliv do stejného souboru)
function removeVpUsersTab(modalEl) {
  if (!modalEl) return;

  // najdi všechny odkazy/tlačítka tabů
  const links = modalEl.querySelectorAll('.nav-tabs .nav-link, .nav-tabs [data-bs-toggle="tab"]');
  if (!links.length) return;

  const norm = (s) => (s || '')
    .toString()
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, ''); // "uživatelé" -> "uzivatele"

  let removedActive = false;

  links.forEach((el) => {
    const txt = norm(el.textContent);
    const target = (el.getAttribute('data-bs-target') || el.getAttribute('href') || '').toLowerCase();

    const isUsersTab =
      txt === 'uzivatele' ||
      ['#vp-users', '#vp-uzivatele', '#users', '#tab-users', '#vp-users-tab', '#vp-users-pane'].includes(target);

    if (isUsersTab) {
      if (el.classList.contains('active')) removedActive = true;

      // zruš i obsahový panel
      if (target && target.startsWith('#')) {
        const pane = modalEl.querySelector(target);
        if (pane) pane.remove();
      }

      // zruš samotný tab (preferuj <li>)
      const li = el.closest('li');
      if (li) li.remove(); else el.remove();
    }
  });

  // pokud jsme odstranili aktivní tab, aktivuj první dostupný
  if (removedActive) {
    const first = modalEl.querySelector('.nav-tabs .nav-link, .nav-tabs [data-bs-toggle="tab"]');
    if (first) {
      try { new bootstrap.Tab(first).show(); } catch (_) { first.click?.(); }
    }
  }
}

  // === NAHRADIT CELOU FUNKCI openVpModal V SOUBORU public/js/pol.vp.core.js ===
// NAHRAĎ touto verzí svoji funkci openVpModal v public/js/pol.vp.core.js
async function openVpModal(polId, polLabel) {
  const modalEl = document.getElementById('vpModal');
  if (!modalEl) return alert('Chybí #vpModal');

  // odstraň záložku "Uživatelé" jistě a hned (idempotentní)
  removeVpUsersTab(modalEl);

  // nastavení hlavičky a vyčištění tabulek
  modalEl.dataset.lastPolId = String(polId || '');
  const head = document.getElementById('vp-pol-head');
  if (head) head.value = polLabel || '';

  const tbSur = document.querySelector('#vp-table-sur tbody');
  const tbPol = document.querySelector('#vp-table-pol tbody');
  renderLoadingRow(tbSur);
  renderLoadingRow(tbPol);

  // zobraz modál
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();

  // cleanup po zavření (vypnutí přepočtu a smazání stavů)
  if (!modalEl._vpCleanupBound) {
    modalEl._vpCleanupBound = true;
    modalEl.addEventListener('hidden.bs.modal', () => {
      const mno = document.getElementById('vp-mnozstvi');
      if (mno) mno.oninput = null;
      modalEl._vpCurrentData = null;
      modalEl._vpBaseAgg = null;
      modalEl._vpBaseKg = null;
    });
  }

  // akční lišta (bez duplicit)
  const host = modalEl.querySelector('#vp-actions-bottom') || modalEl.querySelector('#vp-actions') || modalEl.querySelector('.modal-header');
  if (host && !host.querySelector('.vp-actions')) {
    const grp = document.createElement('div');
    grp.className = 'btn-toolbar gap-2 flex-wrap vp-actions';
    grp.innerHTML = `
      <div class="btn-group me-2" role="group">
        <button class="btn btn-outline-primary btn-sm" id="vp-edit">Editovat</button>
        <button class="btn btn-outline-secondary btn-sm" id="vp-clone">Klonovat</button>
        <button class="btn btn-outline-danger btn-sm" id="vp-del">Smazat</button>
      </div>
      <div class="btn-group" role="group">
        <button class="btn btn-outline-secondary btn-sm" id="vp-print">Tisk</button>
      </div>`;
    host.appendChild(grp);
  }

  try {
    // načti data
    const mnoInput = document.getElementById('vp-mnozstvi');
    const kg0 = Number(mnoInput?.value || 1);
    const data = await fetchVP(polId, kg0);

    // ulož aktuální data pro přepočet
    modalEl._vpCurrentData = data;

    // render tabulek
    if (tbSur) fillVpTable(tbSur, data.suroviny);
    if (tbPol) fillVpTable(tbPol, data.polotovary);

    // součet surovin
    const surSum0 = (data.suroviny || []).reduce((a, r) => a + Number(r.navazit_g || 0), 0);
    if (tbSur) appendVpSumRow(tbSur, surSum0, 'Celkem (suroviny)');

    // agregace – ulož základ pro škálování
    modalEl._vpBaseAgg = null;
    modalEl._vpBaseKg  = kg0;
    if (typeof window.vp_loadAgg === 'function') {
      try {
        const dAgg = await window.vp_loadAgg(polId, kg0);
        modalEl._vpBaseAgg = dAgg?.flat || null;
        modalEl._vpBaseKg  = Number(dAgg?.mnozstvi_kg || kg0) || kg0;
      } catch (_) {}
    }

    // přepočet – vždy přepíšu handler (žádná „paměť“ z předchozího polotovaru)
    if (mnoInput) {
      mnoInput.oninput = () => {
        const kg2 = Number(mnoInput.value || 1);
        const cur = modalEl._vpCurrentData || { suroviny: [], polotovary: [] };

        const recalc = rows => (rows || []).map(r => ({
          ...r,
          navazit_g: Math.round(Number(r.gkg || 0) * kg2 * 100) / 100
        }));

        if (tbSur) {
          const rs = recalc(cur.suroviny);
          fillVpTable(tbSur, rs);
          const sum = rs.reduce((a, r) => a + Number(r.navazit_g || 0), 0);
          appendVpSumRow(tbSur, sum, 'Celkem (suroviny)');
        }
        if (tbPol) fillVpTable(tbPol, recalc(cur.polotovary));

        if (modalEl._vpBaseAgg && typeof window.vp_renderAgg === 'function') {
          const baseKg = modalEl._vpBaseKg || 1;
          const scale = baseKg > 0 ? (kg2 / baseKg) : 1;
          const flat = modalEl._vpBaseAgg.map(x => ({ ...x, total_g: Number(x.total_g || 0) * scale }));
          window.vp_renderAgg(flat);
        }
      };
    }

    // akce
    const editBtn  = modalEl.querySelector('#vp-edit');
    const cloneBtn = modalEl.querySelector('#vp-clone');
    const delBtn   = modalEl.querySelector('#vp-del');
    const printBtn = modalEl.querySelector('#vp-print');

    if (editBtn)  editBtn.onclick  = () => { if (window.openPolEditor) window.openPolEditor(polId); };
    if (cloneBtn) cloneBtn.onclick = async () => {
      const r = await fetch(`/balp2/api/pol_clone2.php?id=${encodeURIComponent(polId)}`, { headers: authHeaders() });
      if (!r.ok) return alert('Klonování selhalo: ' + await r.text());
      const d = await r.json(); if (d.id && window.openPolEditor) window.openPolEditor(d.id);
    };
    if (delBtn)   delBtn.onclick   = async () => {
      if (!confirm('Opravdu smazat tento polotovar?')) return;
      const r = await fetch(`/balp2/api/pol_delete2.php?id=${encodeURIComponent(polId)}`, { headers: authHeaders() });
      if (!r.ok) return alert('Smazání selhalo: ' + await r.text());
      bootstrap.Modal.getOrCreateInstance(modalEl).hide();
      try { document.dispatchEvent(new CustomEvent('pol:reload')); } catch (e) {}
    };
    if (printBtn) printBtn.onclick = () => window.print();

  } catch (e) {
    const msg = (e && e.message) ? e.message : String(e);
    if (tbSur) renderLoadingRow(tbSur, 'Chyba načítání: ' + msg);
    if (tbPol) renderLoadingRow(tbPol, 'Chyba načítání: ' + msg);
  }
}

  // export
  window.fetchVP     = fetchVP;
  window.openVpModal = openVpModal;
})();

