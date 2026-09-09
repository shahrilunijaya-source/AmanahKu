// Inject a CR-27 Mystery Award slide (as the last carousel slide) into the dashboard awards band.
// Builds the band when the dev DB has no award rows. window.__plain: bool
(function(plain){
  const css = `
.uj-ma-slide { display:flex; flex-direction:column; gap:6px; padding:14px 0; }
.uj-ma-k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:#8a5a00; display:inline-flex; align-items:center; gap:6px; }
.uj-ma-env { font-size:22px; line-height:1; }
.uj-ma-cat { font-size:22px; font-weight:700; color:var(--ink); line-height:1.15; letter-spacing:-.01em; }
.uj-ma-sub { font-size:12.5px; color:var(--muted); }
.uj-ma-who { display:flex; align-items:center; gap:8px; margin-top:6px; }
.uj-ma-who .n { font-size:13.5px; font-weight:600; color:var(--ink); }
.uj-ma-who .p { font-size:12px; color:var(--muted); }
.uj-ma-why { font-size:13px; color:var(--body); line-height:1.5; margin:4px 0 0; font-style:italic; max-width:560px; }
.uj-ma-by { font-size:11.5px; color:var(--muted); }
.uj-ma-reveal { animation: uj-ma-in .5s ease-out; }
@keyframes uj-ma-in { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
[data-plain] .uj-ma-reveal { animation:none; }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  let db=document.querySelector('.uj-db');
  if(!db){ db=document.createElement('div'); db.className='uj-db'; const grid=document.querySelector('.uj-dw-grid'); grid.parentNode.insertBefore(db,grid); }
  if(plain) db.setAttribute('data-plain','');
  const winner=`<div class="uj-ma-who" data-winner="6"><span class="uj-db-avatar" style="background:#3a6ea5;width:30px;height:30px;font-size:12px;">AD</span><span class="n">Adri Hakim</span><span class="p">Developer</span></div>`;
  const chosen=`<div data-slide="chosen_one" class="uj-award-result" style="padding:14px 0;">
    <div style="font-size:11px;font-weight:600;color:var(--accent,#3a6ea5);text-transform:uppercase;letter-spacing:.03em;">The Chosen One</div>
    <div style="font-size:12.5px;color:var(--muted);margin-top:2px;">Director's pick, for anything that deserves it</div>
    <div class="uj-ma-who" data-winner="4"><span class="uj-db-avatar" style="background:#b5533c;width:30px;height:30px;font-size:12px;">NH</span><span class="n">Nurin Hazirah</span><span class="p">Project Engineer</span></div>
    <p style="font-size:12px;color:var(--body);margin:6px 0 0;">Director's pick</p></div>`;
  const mystery=`<div data-slide="mystery" class="uj-ma-slide uj-ma-reveal">
    <span class="uj-ma-k">${plain?'':'<span class="uj-ma-env" aria-hidden="true">✉️</span>'}Mystery Award · September</span>
    <span class="uj-ma-cat">Human Google</span>
    <span class="uj-ma-sub">${plain?'One surprise award a month. Category kept sealed until today.':'Nobody knew this category existed until 8:00 this morning.'}</span>
    ${winner}
    <p class="uj-ma-why">“Asked a question in the group chat, got the answer before finishing the sentence. Twice.”</p>
    <span class="uj-ma-by">Picked by this month's mystery committee · not a KPI, no streak, no Hall of Fame</span>
    <div class="uj-bd-react" style="margin-top:8px;"><button type="button" class="uj-react-pick" data-reaction-pick>${plain?'React':'＋ React'}</button><span class="uj-react-tally"><span class="uj-react-chip" data-reaction-count="legend">🏆 4</span><span class="uj-react-chip" data-reaction-count="respect">🫡 2</span></span></div>
  </div>`;
  const sec=document.createElement('section');
  sec.className='uj-db-band uj-db-awards'; sec.setAttribute('data-band','awards');
  sec.innerHTML=`<span class="uj-db-k">${plain?'Awards':'AND THE AWARD GOES TO'}</span>
    <span class="uj-db-t">September's winners</span>
    <span class="uj-db-s">Published 1 Oct. Two slides this month, the last one is a surprise.</span>
    <div class="uj-db-awards-track"><div style="display:none">${chosen}</div><div>${mystery}</div></div>
    <div class="uj-db-awards-dots" role="tablist" style="display:flex;align-items:center;gap:6px;margin-top:8px;">
      <button type="button" style="border:1px solid var(--hairline);background:transparent;border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:13px;line-height:1;">‹</button>
      <button type="button" style="width:8px;height:8px;border-radius:50%;border:0;padding:0;background:var(--hairline)"></button>
      <button type="button" style="width:8px;height:8px;border-radius:50%;border:0;padding:0;background:var(--ink)"></button>
      <button type="button" style="border:1px solid var(--hairline);background:transparent;border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:13px;line-height:1;">›</button>
    </div>
    <a class="uj-db-cta" href="#">View all</a>`;
  db.appendChild(sec);
})(!!window.__plain);
