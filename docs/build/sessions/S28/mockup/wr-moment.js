// Inject the CR-22 company Wrapped moment into the dashboard moments band. window.__plain: bool
(function(plain){
  const css=`
.uj-wr-line { font-size:15px; color:var(--ink); line-height:1.5; flex:1 1 300px; min-width:240px; }
.uj-wr-line b { font-weight:700; }
.uj-wr-foot { font-size:11.5px; color:var(--muted); flex:0 1 200px; }
.uj-wr-num { font-size:40px; font-weight:700; letter-spacing:-.02em; line-height:1; color:var(--red); }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  let db=document.querySelector('.uj-db');
  if(!db){ db=document.createElement('div'); db.className='uj-db'; const grid=document.querySelector('.uj-dw-grid'); grid.parentNode.insertBefore(db,grid); }
  if(plain) db.setAttribute('data-plain','');
  let wrap=db.querySelector('.uj-db-moments'); if(!wrap){ wrap=document.createElement('div'); wrap.className='uj-db-moments'; db.prepend(wrap); }
  const sec=document.createElement('section'); sec.className='uj-db-band uj-db-moment'; sec.setAttribute('data-kind','wrapped'); sec.setAttribute('data-wrapped-company','8');
  sec.innerHTML=`<span class="uj-db-k">${plain?'September, wrapped':'SEPTEMBER, WRAPPED'}</span>
    ${plain?'':'<span class="uj-wr-num" aria-hidden="true">286</span>'}
    <span class="uj-db-t">Unijaya's September in one breath</span>
    <span class="uj-wr-line"><b data-wrapped-stat="cards_closed">286</b> cards closed, <b data-wrapped-stat="lessons_shared">19</b> lessons shared, <b data-wrapped-stat="fires">7</b> fires extinguished and only <b data-wrapped-stat="urgent">43</b> "urgent" tasks.</span>
    <span class="uj-wr-foot">Company totals, frozen with the awards.</span>
    <div class="uj-bd-react"><button type="button" class="uj-react-pick" data-reaction-pick>${plain?'React':'＋ React'}</button><span class="uj-react-tally"><span class="uj-react-chip" data-reaction-count="legend">🏆 6</span><span class="uj-react-chip" data-reaction-count="respect">🫡 3</span></span></div>
    <a class="uj-db-cta" href="#">My Wrapped</a>`;
  wrap.prepend(sec);
})(!!window.__plain);
