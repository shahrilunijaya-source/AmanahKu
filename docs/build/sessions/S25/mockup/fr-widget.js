// Inject the CR-29 Friday sign-off body into the live `friday` widget.
// window.__state: 'prompt' | 'done' | 'mood' | 'few' ; window.__plain: bool
(function(state, plain){
  const css = `
.uj-fr { display:flex; flex-direction:column; gap:12px; }
.uj-fr-k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
.uj-fr-q { font-size:16px; font-weight:600; color:var(--ink); line-height:1.3; }
.uj-fr-moods { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:8px; }
.uj-fr-mood { display:flex; align-items:center; gap:10px; padding:11px 12px; border:1px solid var(--hairline); border-radius:10px; background:#fff; cursor:pointer; font-size:13px; color:var(--ink); text-align:left; transition:border-color 160ms ease, transform 160ms ease; }
.uj-fr-mood:hover { border-color:var(--red); transform:translateY(-1px); }
.uj-fr-mood .uj-fr-art { font-size:18px; line-height:1; }
.uj-fr-mood.is-on { border-color:var(--red); background:#fdf2f2; }
.uj-fr-win { display:flex; flex-direction:column; gap:6px; }
.uj-fr-win label { font-size:12px; color:var(--muted); }
.uj-fr-win input[type=text] { width:100%; height:36px; padding:0 12px; border:1px solid var(--hairline); border-radius:8px; font-size:13px; color:var(--ink); background:#fff; }
.uj-fr-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.uj-fr-share { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--body); }
.uj-fr .uj-btn-primary { height:36px; padding:0 16px; font-size:13px; display:inline-flex; align-items:center; white-space:nowrap; flex-shrink:0; }
.uj-fr-done { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:10px; background:#e6f3ee; color:#1f6b4a; font-size:13px; }
.uj-fr-done b { font-weight:600; }
.uj-fr-bars { display:flex; flex-direction:column; gap:6px; }
.uj-fr-bar { display:grid; grid-template-columns:150px 1fr 38px; align-items:center; gap:8px; font-size:var(--t-sm); color:var(--body); }
.uj-fr-bar .bar { height:8px; border-radius:4px; background:var(--hairline-soft); overflow:hidden; }
.uj-fr-bar .bar i { display:block; height:100%; background:var(--red); border-radius:4px; width:var(--w); animation:uj-fr-grow 600ms var(--ease) both; }
.uj-fr-bar.win { font-weight:600; color:var(--ink); }
.uj-fr-bar .pct { text-align:right; font:600 var(--t-micro) var(--font-mono); color:var(--muted); }
@keyframes uj-fr-grow { from { width:0 } }
.uj-db[data-plain] .uj-fr-bar .bar i { animation:none; }
.uj-fr-meta { font-size:12px; color:var(--muted); }
.uj-fr-wins { display:flex; flex-direction:column; gap:8px; border-top:1px solid var(--hairline-soft); padding-top:12px; }
.uj-fr-winrow { font-size:13px; color:var(--ink); display:flex; gap:8px; align-items:flex-start; }
.uj-fr-winrow .who { font-weight:600; white-space:nowrap; }
.uj-fr-winrow .priv { font-size:11px; color:var(--muted); border:1px solid var(--hairline); border-radius:999px; padding:1px 7px; white-space:nowrap; }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  const moods=[['productive','Productive','🚀'],['chaotic','Chaotic','🌪️'],['peaceful',plain?'Peaceful':'Suspiciously Peaceful','🫖'],['survived','I Survived','🫠']];
  const art=e=>plain?'':`<span class="uj-fr-art" aria-hidden="true">${e}</span>`;
  const kicker=plain?'Friday sign-off':'FRIDAY SIGN-OFF · CLOSES MON 9 AM';
  let html='';
  if (state==='prompt') {
    html=`<div class="uj-fr" data-friday-signoff>
      <span class="uj-fr-k">${kicker}</span>
      <span class="uj-fr-q">This week was…</span>
      <div class="uj-fr-moods">${moods.map(([k,l,e],i)=>`<button type="button" class="uj-fr-mood${i===0?' is-on':''}" data-mood="${k}">${art(e)}${l}</button>`).join('')}</div>
      <div class="uj-fr-win">
        <label for="fr-win">My win this week (optional, one line)</label>
        <input id="fr-win" type="text" name="win" maxlength="160" placeholder="${plain?'What went well':'Small counts. Big counts more.'}" value="Closed the MySToDS audit">
      </div>
      <div class="uj-fr-row">
        <label class="uj-fr-share"><input type="checkbox" name="share" checked> Share my win under my name</label>
        <button type="button" class="uj-btn-primary">Sign off</button>
      </div>
      <span class="uj-fr-meta">Your mood is anonymous. Nobody, not even the Director, can see who picked what.</span>
    </div>`;
  } else if (state==='done') {
    html=`<div class="uj-fr">
      <span class="uj-fr-k">${kicker}</span>
      <div class="uj-fr-done" data-friday-done>${plain?'':'✅ '}<span><b>Signed off.</b> Company mood lands here at 5 PM once five people have answered.</span></div>
      <div class="uj-fr-wins"><div class="uj-fr-winrow" data-friday-my-win><span class="who">You</span><span>Closed the MySToDS audit</span><span class="priv">private</span></div></div>
    </div>`;
  } else {
    const rows=[['productive','Productive',46,true],['chaotic','Chaotic',31,false],['peaceful',plain?'Peaceful':'Suspiciously Peaceful',8,false],['survived',plain?'I Survived':'Refusing to Elaborate',15,false]];
    const mood = state==='few'
      ? `<span class="uj-fr-meta">Company mood needs 5 sign-offs before it shows. 4 so far.</span>`
      : `<div class="uj-fr-bars" data-friday-mood>${rows.map(([k,l,p,w])=>`<div class="uj-fr-bar${w?' win':''}"><span>${l}</span><span class="bar"><i style="--w:${p}%"></i></span><span class="pct" data-mood-pct="${k}">${p}%</span></div>`).join('')}</div>
         <span class="uj-fr-meta">13 signed off · nobody can see who picked what</span>`;
    html=`<div class="uj-fr">
      <span class="uj-fr-k">${plain?'Company mood, Friday 5 PM':'COMPANY MOOD · FRI 5 PM'}</span>
      <span class="uj-fr-q">${plain?'This week was':'This week, Unijaya was…'}</span>
      ${mood}
      <div class="uj-fr-wins">
        <div class="uj-fr-winrow" data-friday-win="1"><span class="who">Yati</span><span>Closed the MySToDS audit</span></div>
        <div class="uj-fr-winrow" data-friday-win="2"><span class="who">Kussairi</span><span>Payroll ran first time, no rollback</span></div>
        <div class="uj-fr-winrow" data-friday-my-win><span class="who">You</span><span>Survived the payroll run</span><span class="priv">private</span></div>
      </div>
    </div>`;
  }
  const w=document.querySelector('[data-widget="friday"] .uj-dw-body');
  w.innerHTML=html;
  if (plain) document.querySelector('.uj-db')?.setAttribute('data-plain','');
})(window.__state||'prompt', window.__plain===true);
