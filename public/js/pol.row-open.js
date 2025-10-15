
// Lightweight, robust opener for Výrobní příkaz from Polotovary table.
// Works even if the table is re-rendered or other scripts are lazy-loaded.
(function(){
  document.addEventListener('click', function(e){
    const tr = e.target && e.target.closest && e.target.closest('#pol-table tbody tr');
    if (!tr) return;
    try{
      const tds = tr.querySelectorAll('td');
      const id   = tr.getAttribute('data-id') || (tds[0] && tds[0].textContent.trim()) || '';
      const code = (tds[1] && tds[1].textContent.trim()) || '';
      const name = (tds[2] && tds[2].textContent.trim()) || '';
      const label = [code, name].filter(Boolean).join(' — ');
      if (id){
        // mark this click to avoid the secondary 'Polotovar' row modal
        try{ const trEl = tr; if(trEl) trEl.classList.add('no-row-modal'); }catch(_){ }

        if (typeof window.openVpModal === 'function'){
          window.openVpModal(id, label);
        }else{
          console.warn('openVpModal není dostupná — zkontroluj načtení pol.vyrobni-prikaz.js');
          alert('Nelze otevřít Výrobní příkaz — skript není načten (pol.vyrobni-prikaz.js).');
        }
      }
      // prevent other click handlers from opening additional modals
      try{ e.stopImmediatePropagation(); e.stopPropagation(); }catch(_){ }
      try{ e.preventDefault(); }catch(_){ }
    }catch(err){
      console.error('Polotovary click handler error:', err);
    }
  }, true); // use capture to catch even when other handlers stopPropagation
})();
