// Inject the CR-26 Side Quests screen body over the live Wins screen (same layout shell).
// window.__plain: bool ; window.__hr: bool (shows curation + suggestions)
(function(plain, hr){
  const css = `
.uj-sq-wrap { display:flex; flex-direction:column; gap:14px; max-width:760px; }
.uj-sq-k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
.uj-sq-quests { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; }
.uj-sq-quest { padding:16px 18px; display:flex; flex-direction:column; gap:8px; position:relative; }
.uj-sq-quest .uj-sq-art { font-size:26px; line-height:1; }
.uj-sq-quest .t { font-size:15px; font-weight:600; color:var(--ink); line-height:1.3; }
.uj-sq-quest .b { font-size:12.5px; color:var(--muted); }
.uj-sq-quest .uj-btn-primary, .uj-sq-quest .uj-btn-ghost { height:32px; padding:0 14px; font-size:12.5px; display:inline-flex; align-items:center; white-space:nowrap; align-self:flex-start; margin-top:auto; }
.uj-sq-quest.is-done { border-color:#bfe3d1; background:#f3faf6; }
.uj-sq-quest .done { font-size:12px; color:#1f6b4a; font-weight:600; align-self:flex-start; margin-top:auto; }
.uj-sq-quest .retire { position:absolute; top:10px; right:12px; font-size:11px; color:var(--muted); background:none; border:0; cursor:pointer; }
.uj-sq-form { display:flex; flex-direction:column; gap:10px; padding:16px 18px; }
.uj-sq-form label { font-size:12px; color:var(--muted); display:flex; flex-direction:column; gap:5px; }
.uj-sq-form input[type=text], .uj-sq-form textarea { width:100%; padding:8px 12px; border:1px solid var(--hairline); border-radius:8px; font-size:13px; color:var(--ink); background:#fff; font-family:inherit; }
.uj-sq-form .row { display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
.uj-sq-form .uj-btn-primary, .uj-sq-form .uj-btn-ghost { height:36px; padding:0 16px; font-size:13px; display:inline-flex; align-items:center; white-space:nowrap; flex-shrink:0; }
.uj-sq-file { font-size:12.5px; color:var(--body); display:inline-flex; align-items:center; gap:6px; border:1px dashed var(--hairline); border-radius:8px; padding:7px 12px; cursor:pointer; }
.uj-sq-feed { display:flex; flex-direction:column; gap:12px; }
.uj-sq-post { padding:16px 18px; display:flex; flex-direction:column; gap:8px; }
.uj-sq-post .who { display:flex; align-items:center; gap:10px; }
.uj-sq-post .who .n { font-size:13.5px; font-weight:600; color:var(--ink); }
.uj-sq-post .who .q { font-size:11px; font-weight:600; color:var(--red); text-transform:uppercase; letter-spacing:.04em; }
.uj-sq-post .who .w { font-size:11px; color:var(--muted); margin-left:auto; white-space:nowrap; }
.uj-sq-post .note { font-size:13.5px; color:var(--body); line-height:1.5; }
.uj-sq-post img { max-width:320px; border-radius:10px; border:1px solid var(--hairline); }
.uj-sq-badge { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; color:#6b3fa0; background:#f4eefb; border:1px solid #d9c8f0; padding:3px 9px; border-radius:9999px; }
.uj-sq-badge small { color:var(--muted); font-weight:500; }
.uj-sq-sugg { padding:14px 18px; display:flex; flex-direction:column; gap:8px; }
.uj-sq-sugg .row { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--ink); }
.uj-sq-sugg .row small { color:var(--muted); }
.uj-sq-sugg .row .uj-btn-ghost { margin-left:auto; height:30px; padding:0 12px; font-size:12px; display:inline-flex; align-items:center; }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  const art=e=>plain?'':`<span class="uj-sq-art" aria-hidden="true">${e}</span>`;
  const quests=[
    {id:1,t:'Share one useful AI prompt',b:'Paste the prompt and what it saved you.',e:'🤖',done:false},
    {id:2,t:'Have lunch with someone outside your project',b:'Photo optional. Receipt not required.',e:'🍜',done:true},
    {id:3,t:'Teach a colleague one shortcut',b:'Keyboard, Excel, life. Anything.',e:'⌨️',done:false},
  ];
  const av=(ini,c)=>`<span class="uj-db-avatar" style="background:${c};width:28px;height:28px;font-size:11px;">${ini}</span>`;
  const react=`<div class="uj-bd-react"><button type="button" class="uj-react-pick" data-reaction-pick>${plain?'React':'＋ React'}</button><span class="uj-react-tally"><span class="uj-react-chip" data-reaction-count="respect">🫡 3</span><span class="uj-react-chip" data-reaction-count="legend">🏆 1</span></span></div>`;
  const html=`<div class="uj-sq-wrap">
    <div>
      <span class="uj-sq-k">${plain?'Optional challenges':'NOT A KPI. NEVER WILL BE.'}</span>
      <p style="font-size:13px;color:var(--muted);margin:4px 0 0;">Three small quests, live until HR swaps them. Finish one, post the proof, wear the badge for 30 days. No points, nothing counts.</p>
    </div>
    <div class="uj-sq-quests">
      ${quests.map(q=>`<div class="uj-card uj-sq-quest${q.done?' is-done':''}" data-quest="${q.id}">
        ${hr?'<button type="button" class="retire">Retire</button>':''}
        ${art(q.e)}<span class="t">${q.t}</span><span class="b">${q.b}</span>
        ${q.done?`<span class="done">${plain?'Done':'✓ Done'} · badge until 9 Oct</span>`:`<button type="button" class="uj-btn-primary">I did this</button>`}
      </div>`).join('')}
    </div>
    <div class="uj-card uj-sq-form" data-quest-complete="1">
      <span class="uj-sq-k" style="color:var(--muted)">Share one useful AI prompt · your proof</span>
      <label>One-liner (or a photo, or both)<textarea name="note" rows="2" maxlength="280" placeholder="Prompt: explain this stack trace like I am new to Laravel.">Prompt: "Explain this stack trace like I am new to Laravel." Saved me an hour.</textarea></label>
      <div class="row"><label class="uj-sq-file">${plain?'':'📎 '}Add a photo <input type="file" name="photo" accept="image/*" hidden></label><button type="button" class="uj-btn-primary">Post it</button></div>
    </div>
    ${hr?`<div class="uj-card uj-sq-sugg"><span class="uj-sq-k" style="color:var(--muted)">Suggested by staff</span>
      <div class="row" data-quest-suggestion="9">${av('SS','#3a6ea5')}<span>Recommend one book, show or cafe</span><small>Shazwan</small><button type="button" class="uj-btn-ghost">Make it live</button></div>
      <div class="row"><input type="text" placeholder="New quest title" style="flex:1;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;"><button type="button" class="uj-btn-ghost">Publish</button></div>
    </div>`:`<div class="uj-card uj-sq-form" style="flex-direction:row;align-items:center;gap:10px;"><input type="text" name="title" placeholder="Suggest a quest for HR to pick up" style="flex:1;height:36px;"><button type="button" class="uj-btn-ghost">Suggest</button></div>`}
    <span class="uj-sq-k" style="color:var(--muted)">Side Quest feed</span>
    <div class="uj-sq-feed">
      <div class="uj-card uj-sq-post" data-quest-post="12"><div class="who">${av('HY','#c2410c')}<span class="n">Haryati</span><span class="q">Lunch outside your project</span><span class="w">Tue 8 Sep</span></div><span class="note">Nasi kandar with the admin team. Learned that Ain runs the office plants on a spreadsheet.</span><img style="width:320px;height:200px;object-fit:cover" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAAAoCAIAAADBrGu+AAAAYUlEQVR4nO3RQQ0AIAwEQfxLQUlFIAYRPCYkm5yAdnad2V9v8Qt6QBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWQBNWYLziyy5gAQ1aefFAtQAAAABJRU5ErkJggg==" alt=""><div class="uj-sq-badgerow"><span class="uj-sq-badge">${plain?'':'🏷️ '}Lunch outside your project <small>· 30 days</small></span></div>${react}</div>
      <div class="uj-card uj-sq-post" data-quest-post="11"><div class="who">${av('SS','#3a6ea5')}<span class="n">Shazwan</span><span class="q">Share one useful AI prompt</span><span class="w">Mon 7 Sep</span></div><span class="note">Prompt: "Summarise this MR in three bullets, flag anything touching payroll." Reviewers stopped asking me what changed.</span>${react}</div>
    </div>
  </div>`;
  const main=document.querySelector('.uj-main .uj-screen, .uj-main main, .uj-main');
  const target=[...document.querySelectorAll('.uj-main div')].find(d=>d.getAttribute('style')?.includes('flex-direction:column;gap:14px'));
  (target||main).outerHTML=html;
  const h=document.querySelector('.uj-main h1'); if(h) h.textContent='Side Quests';
  const sub=h?.nextElementSibling; if(sub&&sub.tagName==='P') sub.textContent='Small optional challenges with nothing to do with KPI. Finish one, post it, wear the badge.';
  if (plain) document.querySelector('.uj-db')?.setAttribute('data-plain','');
})(window.__plain===true, window.__hr===true);
