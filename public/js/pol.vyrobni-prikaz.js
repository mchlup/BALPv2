// public/js/pol.vyrobni-prikaz.js
// UI nadstavba: otevření VP modálu z tabulky polotovarů + „Rozpad (agreg.)“ a jeho vykreslení
(() => {
  const $ = (s, p=document) => p.querySelector(s);

  /* ---------- Tlačítko "Rozpad (agreg.)" v hlavičce modálu ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    const hdr = document.querySelector('#vpModal .modal-header');
    if (!hdr || hdr.querySelector('#vp-recurse')) return;
    const btn = document.createElement('button');
    btn.className = 'btn btn-outline-primary ms-2';
    btn.id = 'vp-recurse';
    btn.textContent = 'Rozpad (agreg.)';
    btn.onclick = onRecurseClick;
    hdr.appendChild(btn);
  });

  async function onRecurseClick() {
    try {
      const kg    = Number(document.getElementById('vp-mnozstvi').value || 1);
      const polId = document.getElementById('vpModal').dataset.lastPolId;
      const h = {'Content-Type':'application/json'};
      try { const t = localStorage.getItem('balp_token'); if (t) h['Authorization']='Bearer '+t; } catch(e){}
      const r = await fetch(`/balp2/api/pol_recurse.php?id=${encodeURIComponent(polId)}&mnozstvi_kg=${encodeURIComponent(kg)}`, {headers:h});
      if (!r.ok) throw new Error(await r.text());
      const d = await r.json();

      const w = window.open('', '_blank');
      w.document.write(`<!doctype html><meta charset="utf-8">
        <title>Rozpad — ${d.pol?.cislo||''} ${d.pol?.nazev||''}</title>
        <style>
          body{font-family:system-ui;padding:1rem}
          table{border-collapse:collapse;width:100%}
          th,td{border:1px solid #ddd;padding:.35rem}
          th{text-align:left}
          td.right{text-align:right}
        </style>
        <h2>Rozpad (agregované suroviny)</h2>
        <p>Polotovar: ${d.pol?.cislo||''} ${d.pol?.nazev||''}<br>Vyrobit: ${Number(d.mnozstvi_kg||0).toFixed(2)} kg</p>
        <table>
          <thead><tr><th>Číslo</th><th>Název</th><th class="right">Celkem navážit (g)</th></tr></thead>
          <tbody>
            ${(d.flat||[]).map(x=>`<tr><td>${x.cislo||''}</td><td>${x.nazev||''}</td><td class="right">${Number(x.total_g||0).toFixed(2)}</td></tr>`).join('')}
          </tbody>
        </table>`);
      w.document.close(); w.focus();
    } catch (e) {
      alert('Rozpad selhal: ' + (e?.message || e));
    }
  }

  /* ---------- Agregace – embed v modálu ---------- */
  window.vp_renderAgg = function(flat) {
    const wrap = document.getElementById('vp-agg-wrap');
    const tb   = document.querySelector('#vp-agg-table tbody');
    if (!wrap || !tb) return;
    wrap.style.display = (flat && flat.length) ? '' : 'none';
    tb.innerHTML = (flat || []).map(x =>
      `<tr><td>${x.cislo||''}</td><td>${x.nazev||''}</td><td class="text-end">${Number(x.total_g||0).toFixed(2)}</td></tr>`
    ).join('');
  };
  window.vp_loadAgg = async function(polId, kg) {
    const h = {'Content-Type':'application/json'};
    try { const t = localStorage.getItem('balp_token'); if (t) h['Authorization']='Bearer '+t; } catch(e) {}
    const r = await fetch(`/balp2/api/pol_recurse.php?id=${encodeURIComponent(polId)}&mnozstvi_kg=${encodeURIComponent(kg)}`, {headers:h});
    if (!r.ok) throw new Error(await r.text());
    const d = await r.json();
    window.vp_renderAgg(d.flat || []);
    return d; // { pol, mnozstvi_kg, flat: [...] }
  };

  /* ---------- Delegovaný klik na řádek polotovaru → otevři modál ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    const tbody = document.querySelector('#pol-table tbody');
    if (!tbody) return;
    tbody.addEventListener('click', (e) => {
      const tr = e.target.closest('tr'); if (!tr) return;
      const cells = tr.querySelectorAll('td'); if (cells.length < 3) return;
      const id   = cells[0].textContent.trim();
      const code = cells[1].textContent.trim();
      const name = cells[2].textContent.trim();
      const label = [code, name].filter(Boolean).join(' — ');
      if (id && typeof window.openVpModal === 'function') window.openVpModal(id, label);
    });
  });
})();

