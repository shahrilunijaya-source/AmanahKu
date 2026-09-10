(function(plain){
  const css = `
.uj-dw-notice[data-plot-twist] { flex-wrap:wrap; }
.uj-dw-notice[data-plot-twist] .when { color:var(--red); }
.uj-pt-art { font-size:18px; line-height:1; display:inline-block; margin-right:4px; animation:uj-pt-flip 700ms var(--ease) 1; }
@keyframes uj-pt-flip { 0%{transform:rotateY(0)} 50%{transform:rotateY(180deg)} 100%{transform:rotateY(360deg)} }
.uj-pt-bars { flex-basis:100%; display:flex; flex-direction:column; gap:5px; margin-top:6px; padding-left:63px; }
.uj-pt-bar { display:grid; grid-template-columns:150px 1fr 38px; align-items:center; gap:8px; font-size:var(--t-sm); color:var(--body); }
.uj-pt-bar .bar { height:8px; border-radius:4px; background:var(--hairline-soft); overflow:hidden; }
.uj-pt-bar .bar i { display:block; height:100%; background:var(--red); border-radius:4px; width:var(--w); animation:uj-pt-grow 600ms var(--ease) both; }
.uj-pt-bar.win { font-weight:600; color:var(--ink); }
.uj-pt-bar .pct { text-align:right; font:600 var(--t-micro) var(--font-mono); color:var(--muted); }
@keyframes uj-pt-grow { from { width:0 } }
.uj-db[data-plain] .uj-pt-bar .bar i { animation:none; }
`;
  const st=document.createElement('style'); st.textContent=css; document.head.appendChild(st);
  const opts=[['Nasi lemak',67,true],['Roti canai',33,false],['Laksa',0,false]];
  const bars=opts.map(([l,p,w],i)=>`<div class="uj-pt-bar${w?' win':''}"><span>${l}</span><span class="bar"><i style="--w:${p}%"></i></span><span class="pct" data-poll-result="${i+1}">${p}%</span></div>`).join('');
  const row=`<div class="uj-dw-notice" data-plot-twist="1">
    <span class="when">Fri<br>3 PM</span>
    <span class="txt">
      <span class="t">${plain?'':'<span class="uj-pt-art" aria-hidden="true">🎲</span>'}Unijaya's unofficial national food?</span>
      <span class="s">${plain?'Weekly poll':'Plot twist'} · 12 voted · nobody can see who picked what</span>
    </span>
    <span class="tag">${plain?'Weekly poll':'PLOT TWIST'}</span>
    <div class="uj-pt-bars">${bars}</div>
  </div>`;
  const body=[...document.querySelectorAll('.uj-dw-body')].find(b=>b.querySelector('.uj-dw-notice')||b.querySelector('.uj-dw-empty'));
  body.querySelector('.uj-dw-empty')?.remove();
  body.insertAdjacentHTML('afterbegin', row);
  if (plain) document.querySelector('.uj-db')?.setAttribute('data-plain','');
})(window.__plain===true);
