// Inject the CR-22 Wrapped screen body over the live Wins screen (same layout shell).
// window.__plain: bool ; window.__hr: bool (shows the arc list)
(function(plain, hr){
  const css = `
.uj-wr-wrap { display:flex; flex-direction:column; gap:14px; max-width:760px; }
.uj-wr-k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
.uj-wr-deck { position:relative; }
.uj-wr-card { border-radius:18px; padding:28px 30px; min-height:240px; display:flex; flex-direction:column; justify-content:flex-end; gap:6px; color:#fff; background:linear-gradient(135deg,#b5202b,#7a1230); box-shadow:0 10px 30px rgba(120,20,40,.18); }
.uj-wr-card[data-tone=ink] { background:linear-gradient(135deg,#1f2937,#0f172a); }
.uj-wr-card[data-tone=amber] { background:linear-gradient(135deg,#c76b12,#8a4a00); }
.uj-wr-card[data-tone=teal] { background:linear-gradient(135deg,#0f766e,#134e4a); }
.uj-wr-card[data-tone=plum] { background:linear-gradient(135deg,#6b3fa0,#3b1d5e); }
.uj-wr-card .uj-wr-art { font-size:34px; line-height:1; margin-bottom:auto; }
.uj-wr-card .big { font-size:52px; font-weight:700; line-height:1; letter-spacing:-.02em; }
.uj-wr-card .line { font-size:17px; font-weight:600; line-height:1.3; }
.uj-wr-card .sub { font-size:12.5px; opacity:.8; }
.uj-wr-nav { display:flex; align-items:center; gap:8px; margin-top:10px; }
.uj-wr-nav button.arrow { border:1px solid var(--hairline); background:transparent; border-radius:50%; width:28px; height:28px; cursor:pointer; font-size:14px; line-height:1; }
.uj-wr-nav .dot { width:8px; height:8px; border-radius:50%; background:var(--hairline); border:0; padding:0; }
.uj-wr-nav .dot.on { background:var(--ink); }
.uj-wr-nav .share { margin-left:auto; height:32px; padding:0 14px; font-size:12.5px; display:inline-flex; align-items:center; white-space:nowrap; }
.uj-wr-shared { font-size:12px; color:#1f6b4a; font-weight:600; margin-left:auto; display:inline-flex; align-items:center; gap:8px; }
.uj-wr-shared button { background:none; border:0; color:var(--muted); font-size:12px; cursor:pointer; text-decoration:underline; }
.uj-wr-plain { font-size:14px; color:var(--body); line-height:1.6; padding:16px 18px; }
.uj-wr-arcs { padding:14px 18px; display:flex; flex-direction:column; gap:8px; }
.uj-wr-arcs .row { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--ink); }
.uj-wr-arcs .row small { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.uj-wr-arcs .row button { margin-left:auto; height:28px; padding:0 10px; font-size:12px; display:inline-flex; align-items:center; }
.uj-wr-arcs form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.uj-wr-arcs input, .uj-wr-arcs select { height:34px; border:1px solid var(--hairline); border-radius:8px; padding:0 10px; font-size:13px; font-family:inherit; }
.uj-wr-arcs form .uj-btn-primary { height:34px; padding:0 14px; font-size:12.5px; display:inline-flex; align-items:center; white-space:nowrap; flex-shrink:0; }
@media (max-width:600px){ .uj-wr-card { padding:22px; min-height:200px; } .uj-wr-card .big { font-size:42px; } }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  const art=e=>plain?'':`<span class="uj-wr-art" aria-hidden="true">${e}</span>`;
  const cards=[
    {tone:'red',e:'🎬',big:'September',line:'Yati, this was your month.',sub:'Numbers and dates only. Nobody else sees this unless you share it.'},
    {tone:'ink',e:'✅',big:'5',line:'You closed 5 cards.',sub:'Same count the awards used, frozen 30 Sep.',stat:['cards_closed','5']},
    {tone:'amber',e:'🔥',big:'2',line:'You survived 2 high-priority situations.',stat:['high_priority','2']},
    {tone:'teal',e:'📅',big:'Tuesday',line:'Most productive day: Tuesday.',sub:'3 of your 5 cards landed on a Tuesday.',stat:['best_day','Tuesday']},
    {tone:'plum',e:'🤝',big:'2',line:'You helped 2 different people finish their work.',stat:['helped_people','2']},
    {tone:'ink',e:'📚',big:'1',line:'1 lesson shared in the Knowledge Bank.',stat:['lessons_shared','1']},
    {tone:'red',e:'🎭',big:'Somehow Got It Done',line:'Your September character arc.',sub:'Picked from HR\'s list by simple rules. No comparison to anyone.',stat:['arc','Somehow Got It Done']},
  ];
  const deck=cards.map((c,i)=>`<div class="uj-wr-card" data-wrapped-card="${i+1}" data-tone="${c.tone}" ${i?'style="display:none"':''}>${art(c.e)}<span class="big" ${c.stat?`data-wrapped-stat="${c.stat[0]}"`:''}>${c.stat?c.stat[1]:c.big}</span><span class="line">${c.line}</span>${c.sub?`<span class="sub">${c.sub}</span>`:''}</div>`).join('');
  const plainText=`<div class="uj-card uj-wr-plain" data-wrapped-plain>September, wrapped. You closed <b data-wrapped-stat="cards_closed">5</b> cards and handled <b data-wrapped-stat="high_priority">2</b> high-priority ones. Your most productive day was <b data-wrapped-stat="best_day">Tuesday</b>. You helped <b data-wrapped-stat="helped_people">2</b> different people and shared <b data-wrapped-stat="lessons_shared">1</b> lesson. Character arc: <b data-wrapped-stat="arc">Somehow Got It Done</b>.</div>`;
  const arcs=hr?`<div class="uj-card uj-wr-arcs">
    <span class="uj-wr-k" style="color:var(--muted)">Character arcs · 32 live</span>
    <span style="font-size:12px;color:var(--muted)">One is picked per person by simple rules: 3+ high-priority = firefighter, helped 3+ people = helper, 10+ closed = closer, 0 closed = quiet, else steady.</span>
    <div class="row" data-wrapped-arc="1"><span>Somehow Got It Done</span><small>steady</small><button type="button" class="uj-btn-ghost">Retire</button></div>
    <div class="row" data-wrapped-arc="2"><span>Fireproof</span><small>firefighter</small><button type="button" class="uj-btn-ghost">Retire</button></div>
    <div class="row" data-wrapped-arc="3"><span>Everyone's Favourite Teammate</span><small>helper</small><button type="button" class="uj-btn-ghost">Retire</button></div>
    <div class="row" data-wrapped-arc="4"><span>Quietly Unstoppable</span><small>closer</small><button type="button" class="uj-btn-ghost">Retire</button></div>
    <span style="font-size:12px;color:var(--muted)">… 28 more</span>
    <form><input name="title" placeholder="New arc title" style="flex:1;min-width:200px"/><select name="rule"><option>steady</option><option>firefighter</option><option>helper</option><option>closer</option><option>quiet</option></select><button type="submit" class="uj-btn-primary">Add</button></form>
  </div>`:'';
  const html=`<div class="uj-wr-wrap" data-wrapped="7">
    <div><span class="uj-wr-k">${plain?'Your month in numbers':'AMANAHKU WRAPPED'}</span>
      <p style="font-size:13px;color:var(--muted);margin:4px 0 0;">Your September, from the same frozen numbers the awards use. Private until you share it. <a href="#" style="color:var(--muted)">Aug ›</a></p></div>
    ${plain?plainText:`<div class="uj-wr-deck">${deck}</div>
    <div class="uj-wr-nav"><button type="button" class="arrow">‹</button>${cards.map((c,i)=>`<button type="button" class="dot${i?'':' on'}"></button>`).join('')}<button type="button" class="arrow">›</button><button type="button" class="uj-btn-primary share">Share to the Wall</button></div>`}
    ${plain?`<div style="display:flex;justify-content:flex-end"><button type="button" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;display:inline-flex;align-items:center;white-space:nowrap">Share to the Wall</button></div>`:''}
    ${arcs}
  </div>`;
  const main=document.querySelector('main');
  const h1=main.querySelector('h1'); if(h1) h1.textContent='Wrapped';
  const sub=h1&&h1.nextElementSibling; if(sub&&sub.tagName==='P') sub.textContent='Your month, story-card style. Numbers and dates only.';
  const target=main.querySelector('[data-win]')?.parentElement || main.lastElementChild;
  target.innerHTML=html;
  // simple deck swipe for the screenshots
  let i=0; const cs=[...document.querySelectorAll('.uj-wr-card')], dots=[...document.querySelectorAll('.uj-wr-nav .dot')];
  const go=d=>{cs[i].style.display='none';dots[i].classList.remove('on');i=(i+d+cs.length)%cs.length;cs[i].style.display='';dots[i].classList.add('on');};
  document.querySelectorAll('.uj-wr-nav .arrow').forEach((b,k)=>b.onclick=()=>go(k?1:-1));
  window.__wrGo=go;
})(!!window.__plain, !!window.__hr);
