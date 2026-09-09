// Inject the CR-27 Mystery Award block into the Awards screen Select tab (director view).
// window.__sealed: bool (a pick already exists for the month, category hidden)
(function(sealed){
  const css = `
.uj-ma-form { display:flex; flex-direction:column; gap:10px; max-width:420px; }
.uj-ma-form label { display:block; font-size:11.5px; color:var(--muted); margin-bottom:4px; }
.uj-ma-form select, .uj-ma-form input, .uj-ma-form textarea { width:100%; border:1px solid var(--hairline); border-radius:8px; padding:8px 10px; font-size:13px; font-family:inherit; color:var(--ink); background:#fff; }
.uj-ma-form select, .uj-ma-form input { height:38px; }
.uj-ma-hint { font-size:11.5px; color:var(--muted); }
.uj-ma-sealed { display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px dashed #d9b36a; background:#fff8e8; border-radius:10px; font-size:12.5px; color:#6b4a00; }
.uj-ma-sealed .env { font-size:20px; line-height:1; }
.uj-ma-chips { display:flex; flex-wrap:wrap; gap:6px; }
.uj-ma-chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; border:1px solid var(--hairline); border-radius:9999px; padding:4px 10px 4px 4px; background:#fff; }
.uj-ma-chip .uj-db-avatar { width:22px; height:22px; font-size:9.5px; }
.uj-ma-h { font-size:13px; font-weight:600; color:var(--ink); }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  const card=[...document.querySelectorAll('.uj-card')].find(c=>c.querySelector('input[name=award_key][value=chosen_one]')) || document.querySelector('.uj-card');
  const av=(i,c)=>`<span class="uj-db-avatar" style="background:${c}">${i}</span>`;
  const committee=`<div style="display:flex;flex-direction:column;gap:8px;">
    <span class="uj-ma-h">Mystery committee · September</span>
    <span class="uj-ma-hint">Three people who pick with you this month. Rotate them whenever you like.</span>
    <div class="uj-ma-chips">
      <span class="uj-ma-chip">${av('AK','#3a6ea5')}Ahmad Kussairi</span>
      <span class="uj-ma-chip">${av('EM','#5b8c5a')}Emysha</span>
      <span class="uj-ma-chip">${av('NH','#b5533c')}Nur Hidayah</span>
      <button type="button" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:12px;">Change</button>
    </div></div>`;
  const form=sealed?`<div class="uj-ma-sealed" data-mystery-picked="2026-09-01"><span class="env">✉️</span><span><b>Sealed.</b> A pick for September is in. Category and reason stay hidden, even here, until 1 Oct at 8:00. Picking again replaces it.</span></div>
    <details style="font-size:12.5px;"><summary style="cursor:pointer;color:var(--muted);">Pick again</summary></details>`
  :`<form class="uj-ma-form" method="post" action="/app/awards/mystery">
    <div><label>Mystery Award · September — colleague</label><select name="employee_id"><option>Adri Hakim</option><option>Emysha</option><option>Nurin Hazirah</option></select>
      <span class="uj-ma-hint">Last month's winner is greyed out, nobody wins twice in a row.</span></div>
    <div><label>Category (make one up)</label><input name="category" maxlength="80" list="ma-ideas" placeholder="Human Google, Bug Whisperer, Meeting Survivor of the Month…" value="Human Google"/>
      <datalist id="ma-ideas"><option>Human Google</option><option>Calm in the Chaos</option><option>Client Translator</option><option>Bug Whisperer</option><option>PowerPoint Has Left the Chat</option><option>The 'I Already Fixed It' Award</option><option>Professional Tab Collector</option><option>Most Likely to Save the Day Quietly</option><option>Meeting Survivor of the Month</option></datalist></div>
    <div><label>Why (funny, kind, one or two lines)</label><textarea name="explanation" rows="3" maxlength="500">Asked a question in the group chat, got the answer before finishing the sentence. Twice.</textarea></div>
    <div style="display:flex;align-items:center;gap:10px;"><button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;display:inline-flex;align-items:center;white-space:nowrap;flex-shrink:0;">Seal it</button><span class="uj-ma-hint">Stays hidden from everyone, you included, until 1 Oct.</span></div>
  </form>`;
  const block=document.createElement('div');
  block.style.cssText='display:flex;flex-direction:column;gap:16px;padding-top:16px;border-top:1px solid var(--hairline);';
  block.innerHTML=`<div><span class="uj-ma-h" style="font-size:14px;">✉️ Mystery Award</span><div class="uj-ma-hint">One surprise a month. No rubric, no points, never counts. Category unknown to everyone until the 1st.</div></div>${form}${committee}`;
  card.appendChild(block);
  // Show the select tab
  const tabBtn=[...document.querySelectorAll('button')].find(b=>/select/i.test(b.textContent)); tabBtn&&tabBtn.click();
})(!!window.__sealed);
