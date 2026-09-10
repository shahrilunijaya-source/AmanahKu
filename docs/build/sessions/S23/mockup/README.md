# S23 / CR-28 Victory Bell: mockup for approval

Three surfaces. Screenshots in this folder; the CSS and markup below are the approved
design once Shazwan says "ok". Shapes (routes, columns, data attributes) are fixed by
`tests/Acceptance/CR28Test.php`; this file only fixes how it looks.

## 1. Dashboard celebration (`dash-bell.png`, `dash-bell-band.png`, `dash-bell-plain.png`, `dash-bell-phone.png`)

One more Moment in the existing moments band, same shell as the Big Deal banner
(reuses `.uj-bd-team`, `.uj-bd-react`, `.uj-db-confetti`). Confetti is the birthday
24-piece burst; the bell icon swings three times on load. Plain mode: no bell icon,
no confetti, no swing, text and reactions stay.

```html
<section class="uj-db-band uj-db-moment" data-kind="victory-bell" data-victory-bell="1">
  <span class="uj-db-k">We have movement</span>
  <span class="uj-vb-bell" aria-hidden="true">🔔</span>            <!-- omitted when plain -->
  <span class="uj-db-t">MySToDS Release 4 is officially Done.</span>
  <span class="uj-bd-team">
    <span class="uj-db-avatar" data-victory-bell-member="26">SZ</span> …owner first, then tagged participants…
    <small>Shazwan, Nabil, Kussairi</small>
  </span>
  <span class="uj-db-s">“Six months. One release. Zero rollbacks.”</span>   <!-- the optional line, italic; omitted when empty -->
  <span class="uj-vb-meta">Rung by <b>Shazwan</b> · MySToDS · on the dashboard until Thu 10 Sep, 10:00 · then on the Wins page</span>
  <div class="uj-bd-react"> …CR-30 picker + tally, same partial pattern as big-deal-react… </div>
  <div class="uj-db-confetti" aria-hidden="true">…24 <i>…</i>…</div>   <!-- omitted when plain -->
</section>
```

```css
.uj-db-band[data-kind="victory-bell"] { flex-wrap:wrap; row-gap:10px; }
.uj-db-band[data-kind="victory-bell"] .uj-db-s { flex-basis:100%; margin-top:-6px; font-style:italic; }
.uj-vb-bell { font-size:22px; line-height:1; transform-origin:top center; animation:uj-vb-swing 900ms var(--ease) 0ms 3; }
@keyframes uj-vb-swing { 0%,100% { transform:rotate(0) } 30% { transform:rotate(-18deg) } 60% { transform:rotate(14deg) } }
.uj-db[data-plain] .uj-vb-bell { animation:none; }
.uj-vb-meta { flex-basis:100%; font-size:11.5px; color:var(--muted); }
.uj-vb-meta b { font-weight:600; color:var(--body); }
```

## 2. "Ring the bell?" prompt on the board (`board-ring-prompt.png`, `board-ring-prompt-card.png`)

Appears bottom-right, toast position, right after a Milestone card lands in Done
(the move answer carries `bell: {work_item_id, prompt}`). Optional one-liner, "Ring it"
posts `POST /app/board/{id}/bell {line}`, "Not now" just closes it (the card's drawer
keeps a "Ring the bell" button for later while the card is Done and unrung). Shows how
many bells the project has left this month. Never appears for a non-milestone card.

```css
.uj-vb-prompt { position:fixed; right:24px; bottom:24px; z-index:60; width:340px; background:#fff; border:1px solid var(--hairline); border-radius:14px; box-shadow:0 12px 32px rgba(0,0,0,.14); padding:14px 16px; display:flex; flex-direction:column; gap:10px; animation:uj-toast-in 260ms var(--ease) both; }
.uj-vb-prompt .k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
.uj-vb-prompt .t { font-size:15px; font-weight:600; color:var(--ink); }
.uj-vb-prompt .s { font-size:12.5px; color:var(--muted); line-height:1.4; }
.uj-vb-prompt input { height:36px; padding:0 10px; border:1px solid var(--hairline); border-radius:8px; font-size:12.5px; }
.uj-vb-prompt .row { display:flex; gap:8px; align-items:center; }
.uj-vb-prompt .uj-btn-primary, .uj-vb-prompt .uj-btn-ghost { height:34px; font-size:12.5px; white-space:nowrap; }
.uj-vb-prompt .count { margin-left:auto; font-size:11px; color:var(--muted-soft); text-align:right; max-width:120px; }
```

## 3. Milestone flag in the card drawer (no screenshot)

PM and above see a "Milestone" toggle row in the card drawer next to Priority (a
checkbox chip, same styling as the label chips). Sets `is_milestone` through the
existing `PATCH /app/board/{id}`. Everyone else sees a read-only "Milestone" badge on
the card and in the drawer when the flag is on. Board card shows a small 🔔 badge
before the title for milestone cards.

## 4. Wins page

A rung bell is listed on `/app/wins` in the same card shape as a Big Deal, kicker line
"Victory bell · Thu 10 Sep 2026 · MySToDS", the title, avatars, the line, reactions.
Big Deals and bells interleave newest first.
