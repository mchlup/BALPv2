// public/js/pol.editor.js (patched 2025-09-03 c)
// - POST nyní přidává ?token=... do URL (kromě Authorization: Bearer) kvůli staršímu backendu.
// - Posílá `rows` i `lines`.
// - Lepší chybová hláška.
// - Odstíníme chybné Bootstrap "tab" odkazy s href != '#...' (např. '../admin_users.php').

(() => {
  const $ = (s, p=document)=>p.querySelector(s);
  const $$ = (s, p=document)=>Array.from(p.querySelectorAll(s));

  const onReady = (fn) => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  };

  const storageKey='balp_token';
  const getToken = ()=>{ try{return localStorage.getItem(storageKey)||'';}catch(e){return '';} };
  const apiHeaders = ()=>{ const h={'Content-Type':'application/json'}; const t=getToken(); if(t) h['Authorization']='Bearer '+t; return h; };

  const withToken = (url)=>{
    const t = getToken();
    if (!t) return url;
    return url + (url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(t);
  };

  const apiGet = async (url)=>{
    const r=await fetch(withToken(url),{headers:apiHeaders()});
    const text = await r.text();
    if(!r.ok) throw new Error(text || (r.status+' '+r.statusText));
    try { return JSON.parse(text); } catch { return {ok:false, raw:text}; }
  };

  const apiPost = async (url, body)=>{
    const r=await fetch(withToken(url),{method:'POST', headers:apiHeaders(), body:JSON.stringify(body)});
    const text = await r.text();
    if(!r.ok) throw new Error(text || (r.status+' '+r.statusText));
    try { return JSON.parse(text); } catch { return {ok:false, raw:text}; }
  };

  // --- Bootstrap tab fix: z linků s data-bs-toggle="tab" a href != '#...' udělej normální link ---
  onReady(()=>{
    document.querySelectorAll('[data-bs-toggle="tab"][href]').forEach(el => {
      const href = el.getAttribute('href') || '';
      if (!href.startsWith('#')) {
        el.removeAttribute('data-bs-toggle');
        el.removeAttribute('role');
        el.removeAttribute('aria-controls');
      }
    });
  });

  function attachSearch(input, typ){
    let box = document.createElement('div');
    box.className='position-absolute bg-white border rounded shadow-sm p-1';
    box.style.zIndex='9999'; box.style.minWidth='300px'; box.style.display='none';
    input.parentElement.style.position='relative';
    input.parentElement.appendChild(box);

    let timer=null;
    input.addEventListener('input', ()=>{
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length<2){ box.style.display='none'; return; }
      timer=setTimeout(async ()=>{
        try{
          const ep = typ==='sur'? '/balp2/api/search_sur.php?q=':'/balp2/api/search_pol.php?q=';
          const data = await apiGet(ep+encodeURIComponent(q));
          box.innerHTML='';
          for(const it of (data.items||[])){
            const a=document.createElement('a');
            a.href='#'; a.className='d-block px-2 py-1 text-decoration-none';
            a.textContent = `${it.cislo} — ${it.nazev}`;
            a.onclick=(e)=>{ e.preventDefault();
              input.value = `${it.cislo} — ${it.nazev}`;
              input.dataset.refId = it.id;
              box.style.display='none';
            };
            box.appendChild(a);
          }
          box.style.display = (box.childNodes.length? 'block':'none');
        }catch(e){ box.style.display='none'; }
      }, 250);
    });
    document.addEventListener('click', (e)=>{ if(!box.contains(e.target) && e.target!==input) box.style.display='none'; });
  }

  function rowTemplate(typ){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <select class="form-select form-select-sm pe-typ">
          <option value="sur" ${typ==='sur'?'selected':''}>surovina</option>
          <option value="pol" ${typ==='pol'?'selected':''}>polotovar</option>
        </select>
      </td>
      <td><input class="form-control form-control-sm pe-ref" placeholder="kód nebo název"><small class="text-muted d-block"></small></td>
      <td style="width:80px"><input class="form-control form-control-sm pe-techpor" placeholder=""></td>
      <td style="width:120px"><input class="form-control form-control-sm text-end pe-gkg" placeholder="0.00"></td>
      <td class="text-end" style="width:60px"><button class="btn btn-sm btn-outline-danger pe-del" title="Smazat řádek">&times;</button></td>
    `;
    const ref = tr.querySelector('.pe-ref');
    attachSearch(ref, typ);
    tr.querySelector('.pe-typ').addEventListener('change', (e)=>{
      ref.value=''; ref.dataset.refId=''; ref.nextElementSibling.textContent='';
      attachSearch(ref, e.target.value);
    });
    tr.querySelector('.pe-del').onclick=()=> tr.remove();
    return tr;
  }

  function gatherRows(tbody){
    const rows=[];
    for(const tr of tbody.querySelectorAll('tr')){
      const typ = tr.querySelector('.pe-typ')?.value || 'sur';
      const ref = tr.querySelector('.pe-ref');
      const ref_id = parseInt(ref?.dataset?.refId || '0', 10);

      const tpStr = tr.querySelector('.pe-techpor')?.value?.trim() ?? '';
      let techpor = parseInt(tpStr, 10);
      if (!Number.isInteger(techpor) || techpor <= 0) techpor = rows.length + 1;

      const gStr = tr.querySelector('.pe-gkg')?.value ?? '';
      let gkg = parseFloat((gStr+'').replace(',','.'));
      if (Number.isNaN(gkg)) gkg = 0;

      if (ref_id > 0){
        rows.push({ typ, ref_id, techpor, gkg });
      }
    }
    return rows;
  }

  async function openEditor(id){
    const modalEl = $('#polEditorModal');
    const tbody = $('#pe-table tbody'); tbody.innerHTML='';
    $('#pe-id').value=''; $('#pe-cislo').value=''; $('#pe-nazev').value=''; $('#pe-sh').value=''; $('#pe-okp').value=''; $('#pe-olej').value=''; $('#pe-poz').value='';
    $('#pe-delete').style.display = id? '' : 'none';
    $('#pe-clone').style.display = id? '' : 'none';

    if (id){
      const d = await apiGet(`/balp2/api/pol_get.php?id=${encodeURIComponent(id)}`);
      const h = d.pol || {};
      $('#pe-id').value = h.id||''; $('#pe-cislo').value=h.cislo||''; $('#pe-nazev').value=h.nazev||''; $('#pe-sh').value=h.sh||''; $('#pe-okp').value=h.okp||''; $('#pe-olej').value=h.olej||''; $('#pe-poz').value=h.pozn||'';
      for(const r of (d.lines||[])){
        const tr = rowTemplate(r.typ);
        const ref = tr.querySelector('.pe-ref');
        ref.value = `${r.cislo||''} — ${r.nazev||''}`;
        ref.dataset.refId = String(r.idsur || r.idpol || '');
        tr.querySelector('.pe-techpor').value = r.techpor || '';
        tr.querySelector('.pe-gkg').value = Number(r.gkg||0).toFixed(2);
        tbody.appendChild(tr);
      }
    }

    $('#pe-add-sur').onclick = ()=>{ tbody.appendChild(rowTemplate('sur')); };
    $('#pe-add-pol').onclick = ()=>{ tbody.appendChild(rowTemplate('pol')); };

    $('#pe-save').onclick = async ()=>{
      const rows = gatherRows(tbody);

      const payload = {
        id: parseInt($('#pe-id').value||'0',10),
        header: {
          cislo: $('#pe-cislo').value.trim()||null,
          nazev: $('#pe-nazev').value.trim()||null,
          sh: $('#pe-sh').value.trim()||null,
          okp: $('#pe-okp').value.trim()||null,
          olej: $('#pe-olej').value.trim()||null,
          pozn: $('#pe-poz').value.trim()||null,
        },
        rows,
        lines: rows
      };

      try{
        const res = await apiPost('/balp2/api/pol_save.php', payload);
        if (res.ok){
          document.dispatchEvent(new CustomEvent('pol:reload'));
          bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        } else {
          const msg = res.err || res.error || res.message || res.detail || (res.raw ? String(res.raw).slice(0,500) : 'Neznámá chyba');
          alert('Uložení selhalo: ' + msg);
        }
      }catch(e){
        const t = (e && e.message) ? e.message.slice(0,500) : 'Neznámá chyba (fetch)';
        alert('Uložení selhalo: ' + t);
      }
    };

    $('#pe-delete').onclick = async ()=>{
      if (!id) return;
      if (!confirm('Opravdu smazat tento polotovar (soft delete)?')) return;
      const r = await apiGet(`/balp2/api/pol_delete2.php?id=${encodeURIComponent(id)}`);
      if (r && r.ok){ document.dispatchEvent(new CustomEvent('pol:reload')); bootstrap.Modal.getOrCreateInstance(modalEl).hide(); }
      else alert('Smazání selhalo');
    };

    $('#pe-clone').onclick = async ()=>{
      if (!id) return;
      const d = await apiGet(`/balp2/api/pol_clone2.php?id=${encodeURIComponent(id)}`);
      if (d && d.id) openEditor(d.id);
      document.dispatchEvent(new CustomEvent('pol:reload'));
    };

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  }

    onReady(()=>{
    // toolbar / header button "Nový polotovar"
    const headerBtn = document.getElementById('pol-new');
    if (headerBtn) {
      headerBtn.addEventListener('click', () => openEditor(null));
    } else {
      // fallback: injektuj malé "Nový" vedle souhrnu, když v hlavičce chybí
      const actionsRow = document.querySelector('#pol-summary')?.parentElement;
      if (actionsRow){
        const btnNew = document.createElement('button');
        btnNew.className='btn btn-primary btn-sm ms-2';
        btnNew.id='pol-new';
        btnNew.textContent='Nový';
        actionsRow.appendChild(btnNew);
        btnNew.onclick = ()=> openEditor(null);
      }
    }

    // doubleclick to edit
    const polTableBody = document.querySelector('#pol-table tbody');
    if (polTableBody){
      const decorate = () => {
        Array.from(polTableBody.querySelectorAll('tr')).forEach(tr => {
          if (tr._editBound) return;
          tr._editBound = true;
          tr.addEventListener('dblclick', ()=>{
            const id = tr.querySelector('td')?.textContent?.trim();
            if (id) openEditor(id);
          });
        });
      };
      decorate();
      const mo = new MutationObserver(decorate);
      mo.observe(polTableBody, {childList:true});
    }

    document.addEventListener('pol:reload', ()=>{
      try{ if (window.pol?.load) window.pol.load(true); }catch(e){}
    });

    // zpřístupni editor zvenku
    window.openPolEditor = openEditor;
  });
})();
