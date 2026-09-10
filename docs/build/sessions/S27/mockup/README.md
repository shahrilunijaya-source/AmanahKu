# S27 / CR-27 Mystery Award: mockup

Two surfaces. Screenshots produced by injecting `ma-band.js` over the live dashboard
(`window.__plain`; the dev DB has no award rows so the script builds the whole band) and
`ma-select.js` over the Awards screen Select tab as the director (`window.__sealed`).
Shapes (routes, tables, data attributes) are fixed by `tests/Acceptance/CR27Test.php`;
this file only fixes how it looks. Copy is EN, BM follows the usual `$store.ui.lang` pattern.

## 1. Dashboard slide (`band.png`, `band-plain.png`, `band-phone.png`)

The existing CR-14b awards band, unchanged, with one extra slide **last** in the track
(`data-slide="mystery"`, `.uj-ma-slide`):

- Kicker `.uj-ma-k` "✉️ MYSTERY AWARD · SEPTEMBER" (amber, envelope dropped when plain).
- The category as the award name, big (`.uj-ma-cat`, 22px bold): "Human Google".
- Sub line `.uj-ma-sub`: "Nobody knew this category existed until 8:00 this morning."
  (plain: "One surprise award a month. Category kept sealed until today.").
- Winner row `.uj-ma-who[data-winner]`: avatar, name, position, same as other slides.
- Explanation `.uj-ma-why` in italic quotes.
- Footer `.uj-ma-by`: "Picked by this month's mystery committee · not a KPI, no streak,
  no Hall of Fame" (or "Picked by the Director").
- The usual CR-30 reaction row underneath (same engagement partial as other slides, if
  the session wires it; optional).
- `.uj-ma-reveal` fade-in on first paint, none when `[data-plain]`. Band kicker/dots/View
  all untouched. The dot for the slide gets aria-label "Mystery Award".

## 2. Awards screen, Select tab (`select.png`, `select-sealed.png`)

Under the two existing pick forms, separated by a hairline:

- Heading "✉️ Mystery Award" + hint "One surprise a month. No rubric, no points, never
  counts. Category unknown to everyone until the 1st."
- **Pick form** `.uj-ma-form` → `POST /app/awards/mystery`: colleague select
  (`employee_id`, last month's winner disabled with "(won last month)"), "Category (make
  one up)" text input `category` maxlength 80 with a `<datalist>` of the spec's examples,
  "Why (funny, kind, one or two lines)" textarea `explanation` maxlength 500, primary
  "Seal it" + hint "Stays hidden from everyone, you included, until 1 Oct."
- **Sealed state** `.uj-ma-sealed[data-mystery-picked="YYYY-MM-01"]` once a pick exists:
  amber dashed box "Sealed. A pick for September is in. Category and reason stay hidden,
  even here, until 1 Oct at 8:00. Picking again replaces it." and a `<details>` "Pick
  again" that reveals the empty form. Never echoes category or explanation.
- **Committee** block: "Mystery committee · September", three `.uj-ma-chip` avatars +
  names, ghost "Change" that swaps to a form with three selects → `POST
  /app/awards/mystery/committee {employee_ids[]}`. Director only; committee members see
  the Select tab with only the Mystery pick form (no other picks, no committee block).

## CSS

```css
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
.uj-ma-form { display:flex; flex-direction:column; gap:10px; max-width:420px; }
.uj-ma-form label { display:block; font-size:11.5px; color:var(--muted); margin-bottom:4px; }
.uj-ma-form select, .uj-ma-form input, .uj-ma-form textarea { width:100%; border:1px solid var(--hairline); border-radius:8px; padding:8px 10px; font-size:13px; font-family:inherit; color:var(--ink); background:#fff; }
.uj-ma-form select, .uj-ma-form input { height:38px; }
.uj-ma-form .uj-btn-primary { height:38px; padding:0 18px; font-size:13px; display:inline-flex; align-items:center; white-space:nowrap; flex-shrink:0; }
.uj-ma-hint { font-size:11.5px; color:var(--muted); }
.uj-ma-sealed { display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px dashed #d9b36a; background:#fff8e8; border-radius:10px; font-size:12.5px; color:#6b4a00; }
.uj-ma-sealed .env { font-size:20px; line-height:1; }
.uj-ma-chips { display:flex; flex-wrap:wrap; gap:6px; }
.uj-ma-chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; border:1px solid var(--hairline); border-radius:9999px; padding:4px 10px 4px 4px; background:#fff; }
.uj-ma-chip .uj-db-avatar { width:22px; height:22px; font-size:9.5px; }
.uj-ma-h { font-size:13px; font-weight:600; color:var(--ink); }
```
