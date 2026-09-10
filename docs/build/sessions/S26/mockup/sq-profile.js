// Add a CR-26 quest badge next to the CR-14b award badges on the profile card.
(function(plain){
  const st=document.createElement('style'); st.textContent=`.uj-sq-badge { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; color:#6b3fa0; background:#f4eefb; border:1px solid #d9c8f0; padding:3px 9px; border-radius:9999px; } .uj-sq-badge small { color:var(--muted); font-weight:500; }`; document.head.appendChild(st);
  const status=[...document.querySelectorAll('.uj-main span')].find(s=>/Active|Probation/.test(s.textContent)&&s.getAttribute('style')?.includes('9999px'));
  const wrap=document.createElement('div'); wrap.setAttribute('style','margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;justify-content:center;');
  wrap.innerHTML=`<span class="uj-sq-badge" data-quest-badge="1" title="Side Quest, until 9 Oct">${plain?'':'🏷️ '}Share one useful AI prompt <small>· until 9 Oct</small></span>`;
  status?.insertAdjacentElement('afterend', wrap);
})(window.__plain===true);
