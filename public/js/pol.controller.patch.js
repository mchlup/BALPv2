// Patch: Polotovary controller (non-destructive)
// This file only ADDS the `pol` controller and listeners.
// It assumes your HTML has a Polotovary tab and panel with these IDs:
//  - tab:   #tab-pol OR #tab-polotovary
//  - panel: #pane-pol OR #pane-polotovary
//  - inputs: #pol-search, #pol-limit
//  - table:  #pol-table (with <tbody>), #pol-prev, #pol-next, #pol-summary
(() => {
  const $  = (s, p=document) => p.querySelector(s);
  const $$ = (s, p=document) => Array.from(p.querySelectorAll(s));
  if (!window.API_URL) window.API_URL = '/balp2/api.php';

  const escapeHtml = (value) => {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };
  const safeCell = (value) => escapeHtml(value ?? '');
  const safeDate = (value) => (value ? escapeHtml(String(value).substring(0, 10)) : '');

  const storageKey='balp_token';
  const getToken = ()=>{ try{return localStorage.getItem(storageKey)||'';}catch(e){return '';} };
  const apiHeaders = ()=>{ const h={'Content-Type':'application/json'}; const t=getToken(); if(t) h['Authorization']='Bearer '+t; return h; };
  async function apiFetch(url, opts={}){
    const full = url+(url.includes('?')?'&':'?')+'_ts='+Date.now();
    const t=getToken(); const withToken = t ? full + '&token='+encodeURIComponent(t) : full;
    const resp = await fetch(withToken, {method:(opts.method||'GET'), headers:{...apiHeaders(),...(opts.headers||{})}, body:opts.body, credentials:'include'});
    const text = await resp.text(); let data=null; try{data=JSON.parse(text);}catch{}
    if(!resp.ok){ throw new Error(data?.error||data?.message||text||('HTTP '+resp.status)); }
    return data ?? text;
  }

  // detect existing controllers to avoid double init
  if (window.__POL_CONTROLLER_INSTALLED__) return;
  window.__POL_CONTROLLER_INSTALLED__ = true;

  const pol = {
    state:{search:'',limit:50,offset:0,sort_col:'id',sort_dir:'ASC',total:0,olej:'',platnost:''},
    els:{},
    ready:false,
    grabDom(){
      // support both naming variants
      this.els.tabBtn = $('#tab-pol') || $('#tab-polotovary');
      this.els.panel  = $('#pane-pol') || $('#pane-polotovary');
      this.els.search = $('#pol-search');
      this.els.limit  = $('#pol-limit');
      this.els.tbody  = $('#pol-table tbody');
      this.els.summary= $('#pol-summary');
      this.els.prev   = $('#pol-prev');
      this.els.next   = $('#pol-next');
      this.els.olej   = $('#pol-filter-olej');
      this.els.platnost = $('#pol-filter-platnost');
      this.els.btnReset = $('#pol-reset');
      // some apps render late; tolerate missing DOM
      this.ready = !!(this.els.panel && this.els.tbody);
    },
    bind(){
      if (!this.ready) return;
      let t=null;
      this.els.search && this.els.search.addEventListener('input', ()=>{ clearTimeout(t); t=setTimeout(()=>{ this.state.search=this.els.search.value.trim(); this.state.offset=0; this.load(); },250); });
      this.els.limit && this.els.limit.addEventListener('change', ()=>{ this.state.limit=parseInt(this.els.limit.value||'50',10); this.state.offset=0; this.load(); });
      this.els.prev && this.els.prev.addEventListener('click', ()=>{ if(this.state.offset===0) return; this.state.offset=Math.max(0,this.state.offset-this.state.limit); this.load(); });
      this.els.next && this.els.next.addEventListener('click', ()=>{ if(this.state.offset+this.state.limit>=this.state.total) return; this.state.offset+=this.state.limit; this.load(); });
      if (this.els.olej) {
        this.state.olej = this.els.olej.value;
        this.els.olej.addEventListener('change', ()=>{ this.state.olej = this.els.olej.value; this.state.offset=0; this.load(); });
      }
      if (this.els.platnost) {
        this.state.platnost = this.els.platnost.value;
        this.els.platnost.addEventListener('change', ()=>{ this.state.platnost = this.els.platnost.value; this.state.offset=0; this.load(); });
      }
      if (this.els.btnReset) {
        this.els.btnReset.addEventListener('click', ()=>{
          this.state = {search:'',limit:50,offset:0,sort_col:'id',sort_dir:'ASC',total:0,olej:'',platnost:''};
          if (this.els.search) this.els.search.value='';
          if (this.els.limit) this.els.limit.value='50';
          if (this.els.olej) this.els.olej.value='';
          if (this.els.platnost) this.els.platnost.value='';
          if (this.els.summary) this.els.summary.textContent='—';
          this.load(true);
        });
      }
      // sorting by clicking .sortable headers with data-col
      $$('#pol-table thead th.sortable', this.els.panel).forEach(th=>{
        th.style.cursor='pointer';
        th.addEventListener('click', ()=>{
          const col=th.getAttribute('data-col');
          if (this.state.sort_col===col) this.state.sort_dir=(this.state.sort_dir==='ASC'?'DESC':'ASC');
          else { this.state.sort_col=col; this.state.sort_dir='ASC'; }
          this.load();
        });
      });
      // load when tab shown
      if (this.els.tabBtn) {
        this.els.tabBtn.addEventListener('shown.bs.tab', ()=> this.load(true));
      }
    },
    async load(force=false){
      if (!this.ready) return;
      try{
        const q=new URLSearchParams({
          search:this.state.search,
          limit:String(this.state.limit),
          offset:String(this.state.offset),
          sort_col:this.state.sort_col,
          sort_dir:this.state.sort_dir
        });
        if (this.state.olej !== '') q.set('olej', this.state.olej);
        if (this.state.platnost) q.set('platnost', this.state.platnost);
        const data = await apiFetch('/balp2/api/pol_list.php?'+q.toString());
        this.state.total = data.total||0;
        this.render(data.items||[]);
        const from=Math.min(this.state.total,this.state.offset+1), to=Math.min(this.state.total,this.state.offset+this.state.limit);
        if (this.els.summary) this.els.summary.textContent = this.state.total?`${from}–${to} / ${this.state.total}`:'Žádné záznamy';
      }catch(e){
        console.error('pol load failed', e);
        const box = document.getElementById('alert-box');
        if (box){ box.className='alert alert-danger'; box.textContent='Načítání polotovarů selhalo: '+e.message; box.classList.remove('d-none'); }
      }
    },
    render(items){
      if (!this.els.tbody) return;
      this.els.tbody.innerHTML='';
      for (const r of items){
        const tr=document.createElement('tr');
        tr.innerHTML = `<td>${safeCell(r.id)}</td><td>${safeCell(r.cislo)}</td><td>${safeCell(r.nazev)}</td><td>${safeCell(r.sh)}</td><td>${safeCell(r.okp)}</td><td>${safeCell(r.olej)}</td><td>${safeCell(r.pozn)}</td><td>${safeDate(r.dtod)}</td><td>${safeDate(r.dtdo)}</td>`;
        this.els.tbody.appendChild(tr);
      }
    }
  };

  // init when DOM ready
  document.addEventListener('DOMContentLoaded', ()=>{
    pol.grabDom();
    pol.bind();
    // auto-load if tab is already active on initial render
    if (pol.ready && (pol.els.panel?.classList.contains('active'))) { pol.load(true); }
  });
})();