(function(state){
  const css = `
.uj-pt-wrap { display:flex; flex-direction:column; gap:14px; max-width:720px; padding-top:72px; }
.uj-pt-hero { padding:22px 24px; display:flex; flex-direction:column; gap:10px; position:relative; overflow:hidden; }
.uj-pt-k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
.uj-pt-q { font-size:22px; font-weight:600; color:var(--ink); line-height:1.25; }
.uj-pt-meta { font-size:12px; color:var(--muted); }
.uj-pt-opts { display:flex; flex-direction:column; gap:8px; margin-top:4px; }
.uj-pt-opt { display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid var(--hairline); border-radius:10px; background:#fff; cursor:pointer; font-size:14px; color:var(--ink); text-align:left; transition:border-color 160ms ease, transform 160ms ease; }
.uj-pt-opt:hover { border-color:var(--red); transform:translateX(2px); }
.uj-pt-opt .dot { width:18px; height:18px; border-radius:50%; border:2px solid var(--hairline); flex-shrink:0; }
.uj-pt-opt.is-on { border-color:var(--red); background:var(--red-tint, #fdf2f2); }
.uj-pt-opt.is-on .dot { border-color:var(--red); background:var(--red); box-shadow:inset 0 0 0 3px #fff; }
.uj-pt-voted { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:10px; background:var(--info-tint); color:var(--info-ink); font-size:13px; }
.uj-pt-art-big { position:absolute; right:18px; top:14px; font-size:44px; opacity:.18; transform:rotate(12deg); }
.uj-pt-bars { display:flex; flex-direction:column; gap:7px; margin-top:6px; }
.uj-pt-bar { display:grid; grid-template-columns:170px 1fr 44px; align-items:center; gap:10px; font-size:14px; color:var(--body); }
.uj-pt-bar .bar { height:10px; border-radius:5px; background:var(--hairline-soft); overflow:hidden; }
.uj-pt-bar .bar i { display:block; height:100%; background:var(--red); border-radius:5px; width:var(--w); animation:uj-pt-grow 600ms var(--ease) both; }
.uj-pt-bar.win { font-weight:600; color:var(--ink); }
.uj-pt-bar .pct { text-align:right; font:600 12px var(--font-mono); color:var(--muted); }
@keyframes uj-pt-grow { from { width:0 } }
.uj-pt-suggest { padding:16px 20px; display:flex; flex-direction:column; gap:8px; }
.uj-pt-suggest h3 { margin:0; font-size:14px; font-weight:600; color:var(--ink); }
.uj-pt-suggest p { margin:0; font-size:12.5px; color:var(--muted); }
.uj-pt-suggest .row { display:flex; gap:8px; }
.uj-pt-suggest input, .uj-pt-suggest select, .uj-pt-pub input, .uj-pt-pub select { height:36px; padding:0 10px; border:1px solid var(--hairline); border-radius:8px; font-size:13px; background:#fff; }
.uj-pt-suggest input { flex:1; min-width:0; }
.uj-pt-wrap .uj-btn-primary, .uj-pt-wrap .uj-btn-ghost { height:36px; padding:0 16px; font-size:13px; display:inline-flex; align-items:center; white-space:nowrap; flex-shrink:0; }
.uj-pt-pub { padding:16px 20px; display:flex; flex-direction:column; gap:10px; }
.uj-pt-pub h3 { margin:0; font-size:14px; font-weight:600; color:var(--ink); }
.uj-pt-pub label { display:flex; flex-direction:column; gap:4px; font-size:12px; color:var(--muted); }
.uj-pt-pub .grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.uj-pt-pub .opts { display:flex; flex-direction:column; gap:6px; }
.uj-pt-pub .hint { font-size:11.5px; color:var(--muted-soft); }
.uj-pt-bank { display:flex; flex-wrap:wrap; gap:6px; }
.uj-pt-bank button { font-size:12px; padding:5px 10px; border:1px solid var(--hairline); border-radius:999px; background:#fff; cursor:pointer; color:var(--body); }
.uj-pt-bank button:hover { border-color:var(--red); color:var(--red); }
.uj-pt-optout { padding:14px 18px; display:flex; align-items:center; gap:12px; background:var(--warn-tint, #fff7e6); border:1px solid var(--warn, #e0b25a); border-radius:12px; font-size:13px; color:var(--ink); }
.uj-pt-optout .uj-btn-ghost { margin-left:auto; height:32px; font-size:12.5px; }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  const opts=['Nasi lemak','Roti canai','Laksa','Char kuey teow'];
  const optHtml = opts.map((o,i)=>`<button type="button" class="uj-pt-opt${state==='voted'&&i===0?' is-on':''}" data-option="${i+1}"><span class="dot"></span>${o}</button>`).join('');
  const res=[['Nasi lemak',67,true],['Roti canai',25,false],['Laksa',8,false],['Char kuey teow',0,false]];
  const bars=res.map(([l,p,w],i)=>`<div class="uj-pt-bar${w?' win':''}"><span>${l}</span><span class="bar"><i style="--w:${p}%"></i></span><span class="pct" data-poll-result="${i+1}">${p}%</span></div>`).join('');
  let hero;
  if (state==='results') {
    hero=`<span class="uj-pt-k">Plot twist · revealed Fri 11 Sep, 3 PM</span>
      <span class="uj-pt-q">Unijaya's unofficial national food?</span>
      <span class="uj-pt-meta">12 voted · nobody, not even the Director, can see who picked what · next poll opens Monday</span>
      <div class="uj-pt-bars">${bars}</div>`;
  } else {
    hero=`<span class="uj-pt-k">This week's plot twist · closes Fri 11 Sep, 3 PM</span>
      <span class="uj-pt-q">Unijaya's unofficial national food?</span>
      <span class="uj-pt-meta">Anonymous. Your pick is stored without your name; a hashed receipt only stops you voting twice.</span>
      ${state==='voted'
        ? `<div class="uj-pt-voted">✅ Vote in. Results land on the Notice board Friday at 3 PM.</div><div class="uj-pt-opts">${optHtml}</div>`
        : `<div class="uj-pt-opts">${optHtml}</div>`}`;
  }
  const publish = state==='hr' ? `
  <div class="uj-card uj-pt-pub" data-plot-twist-publish>
    <h3>Publish next week's poll</h3>
    <span class="hint">HR and Director only. Opens Monday 8 AM, reveals Friday 3 PM. Banned: performance, appearance, personal life, anything that could embarrass.</span>
    <div class="uj-pt-bank">
      <button type="button">Who would survive longest in a zombie apocalypse?</button>
      <button type="button">Which project deserves its own Netflix documentary?</button>
      <button type="button">What should the next social activity be?</button>
      <button type="button">Most likely to reply "Noted" within seven seconds?</button>
    </div>
    <label>Question <input value="Who would survive longest in a zombie apocalypse?"></label>
    <div class="grid">
      <label>Kind <select><option>Fun</option><option selected>Who (named person, template only)</option><option>Social activity (feeds CR-18 idea)</option></select></label>
      <label>Opens on <input type="date" value="2026-09-14"></label>
    </div>
    <label>Named person (Who questions) <select><option>Nabil</option></select></label>
    <span class="hint">Nabil gets a heads-up now and can opt out any time before Monday. If he does, the poll is withdrawn and never shows.</span>
    <div class="opts">
      <label>Options (2 to 6)</label>
      <input value="Nabil"><input value="Kussairi"><input value="Shazwan"><input placeholder="Add another">
    </div>
    <div style="display:flex;gap:8px;"><button class="uj-btn-primary" type="button">Publish for Mon 14 Sep</button><button class="uj-btn-ghost" type="button">Save as draft</button></div>
  </div>` : '';
  const optout = state==='optout' ? `
  <div class="uj-pt-optout" data-plot-twist-optout>🙋 <span>Next week's poll names you: <b>"Who would survive longest in a zombie apocalypse?"</b> You can sit this one out before Monday, no questions asked.</span><button class="uj-btn-ghost" type="button">Opt out</button></div>` : '';
  const html=`<div class="uj-pt-wrap">
    ${optout}
    <div class="uj-card uj-pt-hero" data-plot-twist="1">
      <span class="uj-pt-art-big" aria-hidden="true">🎲</span>
      ${hero}
    </div>
    ${publish}
    <div class="uj-card uj-pt-suggest" data-plot-twist-suggest>
      <h3>Got a better question?</h3>
      <p>Anyone can suggest. HR picks from the bank. Keep it kind: nothing about performance, looks or private life.</p>
      <div class="row"><input placeholder="e.g. Which meeting could have been a Telegram message?"><select><option>Fun</option><option>Who</option><option>Social</option></select><button class="uj-btn-primary" type="button">Suggest</button></div>
    </div>
  </div>`;
  window.scrollTo(0,0); document.querySelector(".uj-main")?.scrollTo(0,0);
  const target=document.querySelector('.uj-dw-page') || document.querySelector('main');
  target.innerHTML=html;
  const h=document.querySelector('h1'); if (h) h.textContent="This Week's Plot Twist";
  document.title="This Week's Plot Twist · Amanahku";
})(window.__ptState||'open');
