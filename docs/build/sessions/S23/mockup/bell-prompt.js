(function(){
  const css = `
.uj-vb-prompt { position:fixed; right:24px; bottom:24px; z-index:60; width:340px; background:#fff; border:1px solid var(--hairline); border-radius:14px; box-shadow:0 12px 32px rgba(0,0,0,.14); padding:14px 16px; display:flex; flex-direction:column; gap:10px; font-family:var(--font-sans); animation:uj-toast-in 260ms var(--ease) both; }
.uj-vb-prompt .k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
.uj-vb-prompt .t { font-size:15px; font-weight:600; color:var(--ink); }
.uj-vb-prompt .s { font-size:12.5px; color:var(--muted); line-height:1.4; }
.uj-vb-prompt input { height:36px; padding:0 10px; border:1px solid var(--hairline); border-radius:8px; font-size:12.5px; outline:none; }
.uj-vb-prompt .row { display:flex; gap:8px; align-items:center; }
.uj-vb-prompt .uj-btn-primary { height:34px; padding:0 14px; font-size:12.5px; white-space:nowrap; }
.uj-vb-prompt .uj-btn-ghost { height:34px; padding:0 12px; font-size:12.5px; white-space:nowrap; }
.uj-vb-prompt .count { margin-left:auto; font-size:11px; color:var(--muted-soft); text-align:right; max-width:120px; }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  const el=document.createElement('div'); el.className='uj-vb-prompt'; el.setAttribute('role','dialog');
  el.innerHTML = `<span class="k">🔔 Milestone done</span>
    <span class="t">Ring the bell?</span>
    <span class="s">MySToDS Release 4 just hit Done. Ringing it puts a 24-hour celebration on everyone's dashboard, then files it under Wins.</span>
    <input placeholder="One line for the team (optional)" value="Six months. One release. Zero rollbacks." maxlength="160" />
    <div class="row"><button type="button" class="uj-btn-primary">Ring it</button><button type="button" class="uj-btn-ghost">Not now</button><span class="count">2 of 3 bells left for MySToDS this month</span></div>`;
  document.body.appendChild(el);
})();
