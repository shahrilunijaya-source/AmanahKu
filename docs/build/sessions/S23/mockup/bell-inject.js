(function(plain){
  const css = `
.uj-db-band[data-kind="victory-bell"] { flex-wrap:wrap; row-gap:10px; }
.uj-db-band[data-kind="victory-bell"] .uj-db-s { flex-basis:100%; margin-top:-6px; font-style:italic; }
.uj-vb-bell { font-size:22px; line-height:1; transform-origin:top center; animation:uj-vb-swing 900ms var(--ease) 0ms 3; }
@keyframes uj-vb-swing { 0%,100% { transform:rotate(0) } 30% { transform:rotate(-18deg) } 60% { transform:rotate(14deg) } }
.uj-db[data-plain] .uj-vb-bell { animation:none; }
.uj-vb-meta { flex-basis:100%; font-size:11.5px; color:var(--muted); }
.uj-vb-meta b { font-weight:600; color:var(--body); }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  const picks=[['power','⚡','Power'],['legend','🏆','Legend'],['chefs_kiss','🤌',"Chef's Kiss"],['noted_with_fear','😨','Noted With Fear'],['send_help','🆘','Send Help'],['respect','🫡','Respect'],['how_did_you_do_this','🤯','How Did You Do This?'],['claim_bila','🧾','Claim Bila?']];
  const pickHtml = picks.map(([k,i,l])=>`<button type="button" class="uj-react-pick${k==='legend'?' is-on':''}" data-reaction-pick="${k}"><span aria-hidden="true">${i}</span> ${l}</button>`).join('');
  const confetti = plain ? '' : `<div class="uj-db-confetti" aria-hidden="true">${Array.from({length:24},(_,i)=>`<i style="left:${(i*41)%100}%;top:${(i*23)%60}%;--r:${(i*37)%180-90}deg;--d:${(i*35)%500}ms"></i>`).join('')}</div>`;
  const html = `<div class="uj-db"${plain?' data-plain=""':''}>
  <section class="uj-db-band uj-db-moment" data-kind="victory-bell" data-victory-bell="1" aria-label="MySToDS Release 4 is officially Done.">
    <span class="uj-db-k">We have movement</span>
    ${plain?'':'<span class="uj-vb-bell" aria-hidden="true">🔔</span>'}
    <span class="uj-db-t">MySToDS Release 4 is officially Done.</span>
    <span class="uj-bd-team" aria-label="Team">
      <span class="uj-db-avatar" data-victory-bell-member="26" style="background:#b8632f">SZ</span>
      <span class="uj-db-avatar" data-victory-bell-member="6" style="background:#5b7f3a">NA</span>
      <span class="uj-db-avatar" data-victory-bell-member="5" style="background:#3a6ea5">KU</span>
      <small>Shazwan, Nabil, Kussairi</small>
    </span>
    <span class="uj-db-s">“Six months. One release. Zero rollbacks.”</span>
    <span class="uj-vb-meta">Rung by <b>Shazwan</b> · MySToDS · on the dashboard until Thu 10 Sep, 10:00 · then on the Wins page</span>
    <div class="uj-bd-react">
      <div class="uj-react-pick-row">${pickHtml}</div>
      <span class="uj-react-tally"><span class="uj-react-chip" data-reaction-count="legend">3 🏆</span><span class="uj-react-chip" data-reaction-count="respect">2 🫡</span></span>
    </div>
    ${confetti}
  </section>
</div>`;
  const old=document.querySelector('.uj-db');
  const wrap=document.createElement('div'); wrap.innerHTML=html;
  if(old){ old.replaceWith(wrap.firstElementChild); } else { const main=document.querySelector('main'); main.insertBefore(wrap.firstElementChild, main.firstElementChild); }
})(window.__PLAIN__===true);
