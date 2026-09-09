// Inject a shared Wrapped card at the top of the Wins wall.
(function(){
  const list=document.querySelector('main [data-win]')?.parentElement || document.querySelector('main').lastElementChild;
  const d=document.createElement('div'); d.className='uj-card'; d.setAttribute('data-win-wrapped','7');
  d.style.cssText='padding:18px 20px;display:flex;flex-direction:column;gap:8px;border-left:4px solid var(--red);';
  d.innerHTML=`<span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;">Wrapped · September 2026</span>
    <span style="display:flex;align-items:center;gap:8px;"><span class="uj-db-avatar" style="background:#b5533c;width:28px;height:28px;font-size:11px;">HY</span><span style="font-size:15px;font-weight:600;color:var(--ink);">Haryati</span><span style="font-size:12px;color:var(--muted);">Senior Manager</span></span>
    <span style="font-size:16px;font-weight:600;color:var(--ink);">Character arc: Somehow Got It Done</span>
    <span style="font-size:13px;color:var(--body);">5 cards closed · 2 high-priority · most productive on Tuesdays · helped 2 people · 1 lesson shared</span>`;
  list.prepend(d);
})();
