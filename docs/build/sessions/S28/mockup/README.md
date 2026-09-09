# S28 / CR-22 Amanahku Wrapped: mockup

Three surfaces. Screenshots produced by injecting `wr-screen.js` over the live Wins screen
(`window.__plain`, `window.__hr`), `wr-moment.js` over the dashboard (`window.__plain`) and
`wr-wins.js` over the Wins wall. Shapes (command, tables, routes, data attributes) are fixed
by `tests/Acceptance/CR22Test.php`; this file only fixes how it looks. Copy is EN, BM follows
the usual `$store.ui.lang` pattern.

## 1. Playground screen `/app/wrapped` (`screen-card1.png`, `screen-card4.png`, `screen-card7-arc.png`, `screen-phone.png`, `screen-plain.png`)

Sidebar entry "Wrapped" under The Playground, guide line "Your month, story-card style.
Numbers and dates only." Top to bottom (`.uj-wr-wrap[data-wrapped]`):

- Kicker `.uj-wr-k` "AMANAHKU WRAPPED" (plain: "Your month in numbers") and one line
  "Your September, from the same frozen numbers the awards use. Private until you share
  it." with a small month switcher link (`?month=`).
- **Deck** `.uj-wr-deck`: 7 story cards `.uj-wr-card[data-wrapped-card="n"][data-tone]`
  (tones red / ink / amber / teal / plum, gradient backgrounds, white text, 18px radius,
  min-height 240px), one visible at a time (Alpine `i`, arrows, dots, swipe on touch like
  the awards carousel, no auto-advance). Each card: emoji art `.uj-wr-art` (dropped when
  plain, but plain never shows the deck anyway), the big number or word `.big` carrying
  `data-wrapped-stat`, the sentence `.line`, an optional `.sub`. Order and copy:
  1. red 🎬 "September" / "Yati, this was your month." / "Numbers and dates only. Nobody else sees this unless you share it."
  2. ink ✅ `cards_closed` / "You closed 5 cards." / "Same count the awards used, frozen 30 Sep."
  3. amber 🔥 `high_priority` / "You survived 2 high-priority situations."
  4. teal 📅 `best_day` / "Most productive day: Tuesday." / "3 of your 5 cards landed on a Tuesday."
  5. plum 🤝 `helped_people` / "You helped 2 different people finish their work."
  6. ink 📚 `lessons_shared` / "1 lesson shared in the Knowledge Bank."
  7. red 🎭 `arc` / "Your September character arc." / "Picked from HR's list by simple rules. No comparison to anyone."
  (A person with 0 closed cards gets the same 7; card 4 says "No cards closed this month, and that is fine.")
- **Nav row** `.uj-wr-nav`: ‹ dots › left, primary "Share to the Wall" right
  (`POST /app/wrapped/{story}/share`). Once shared: green "Shared on the Wall · Unshare"
  (`.uj-wr-shared`, unshare is a text button).
- **Plain** (`[data-wrapped-plain]`): one `.uj-card.uj-wr-plain` paragraph with every
  number bold and `data-wrapped-stat` on each, no deck, ghost "Share to the Wall" under it.
- **HR / director only** (`screen-hr-arcs.png`): `.uj-card.uj-wr-arcs` "Character arcs ·
  N live", the rule explainer line, rows `[data-wrapped-arc]` title + rule tag + ghost
  "Retire" (`POST /app/wrapped/arcs/{arc}/retire`), and an add form title + rule select +
  primary "Add" (`POST /app/wrapped/arcs`).

## 2. Dashboard moment (`moment.png`, `moment-plain.png`)

One more moment in the `moments` band, `data-kind="wrapped"`, existing `.uj-db-moment`
styling: kicker "SEPTEMBER, WRAPPED", big red number `.uj-wr-num` (cards closed, dropped
when plain), title "Unijaya's September in one breath", the sentence `.uj-wr-line` with
each number bold and `data-wrapped-stat` (`cards_closed`, `lessons_shared`, `fires`,
`urgent`), footnote `.uj-wr-foot` "Company totals, frozen with the awards.", the CR-30
reaction row (`data-reaction-pick` + tallies), CTA "My Wrapped" → `/app/wrapped`.
Plain: kicker in sentence case, no number art, otherwise identical.

## 3. Wins wall card (`wins-card.png`)

`.uj-card[data-win-wrapped]` with a red left border: "WRAPPED · SEPTEMBER 2026", avatar +
name + position, "Character arc: <title>", one line of the numbers joined by " · ".

## CSS

```css
.uj-wr-wrap { display:flex; flex-direction:column; gap:14px; max-width:760px; }
.uj-wr-k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
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
.uj-wr-plain { font-size:14px; color:var(--body); line-height:1.6; padding:16px 18px; }
.uj-wr-arcs { padding:14px 18px; display:flex; flex-direction:column; gap:8px; }
.uj-wr-arcs .row { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--ink); }
.uj-wr-arcs .row small { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
.uj-wr-arcs .row button { margin-left:auto; height:28px; padding:0 10px; font-size:12px; display:inline-flex; align-items:center; }
.uj-wr-arcs form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.uj-wr-arcs input, .uj-wr-arcs select { height:34px; border:1px solid var(--hairline); border-radius:8px; padding:0 10px; font-size:13px; font-family:inherit; }
.uj-wr-arcs form .uj-btn-primary { height:34px; padding:0 14px; font-size:12.5px; display:inline-flex; align-items:center; white-space:nowrap; flex-shrink:0; }
.uj-wr-line { font-size:15px; color:var(--ink); line-height:1.5; flex:1 1 300px; min-width:240px; }
.uj-wr-foot { font-size:11.5px; color:var(--muted); flex:0 1 200px; }
.uj-wr-num { font-size:40px; font-weight:700; letter-spacing:-.02em; line-height:1; color:var(--red); }
@media (max-width:600px){ .uj-wr-card { padding:22px; min-height:200px; } .uj-wr-card .big { font-size:42px; } }
```
