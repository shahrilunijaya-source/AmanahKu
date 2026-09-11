# S24 / CR-25 This Week's Plot Twist: mockup for approval

Three surfaces. Screenshots in this folder (`pt-notice.js`, `pt-screen.js` are the
inject scripts that produced them). Shapes (routes, tables, data attributes, the
anonymity model) are fixed by `tests/Acceptance/CR25Test.php`; this file only fixes
how it looks. All copy is EN, BM follows the usual `$store.ui.lang` pattern.

## 1. Notice board result row, Friday 3 PM (`dash-notice.png`, `dash-notice-widget.png`, `dash-notice-plain.png`)

One extra row at the top of the existing Notice board widget from `reveals_at` until
the next week's poll opens. Same `.uj-dw-notice` shell as every other notice; the
"when" column reads "Fri / 3 PM" in red, the tag reads PLOT TWIST, a 🎲 flips once on
load, and a bar per option grows in. Winner in bold. Plain: no dice, no bar animation,
tag and kicker become "Weekly poll".

```html
<div class="uj-dw-notice" data-plot-twist="7">
  <span class="when">Fri<br>3 PM</span>
  <span class="txt">
    <span class="t"><span class="uj-pt-art" aria-hidden="true">🎲</span>Unijaya's unofficial national food?</span>
    <span class="s">Plot twist · 12 voted · nobody can see who picked what</span>
  </span>
  <span class="tag">PLOT TWIST</span>
  <div class="uj-pt-bars">
    <div class="uj-pt-bar win"><span>Nasi lemak</span><span class="bar"><i style="--w:67%"></i></span><span class="pct" data-poll-result="21">67%</span></div>
    …one per option, 0% rows included…
  </div>
</div>
```

```css
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
```

## 2. Playground screen `/app/plot-twist` (`screen-open.png`, `screen-voted.png`, `screen-results.png`, `screen-optout.png`, `screen-open-phone.png`)

Sidebar entry "Plot Twist" under The Playground. One hero card:

- **Open, not voted**: kicker "THIS WEEK'S PLOT TWIST · CLOSES FRI 11 SEP, 3 PM", the
  question, the anonymity line, one radio-style button per option. Clicking posts the
  vote straight away (`POST /app/plot-twist/{poll}/vote`), no confirm step.
- **Voted**: blue "✅ Vote in. Results land on the Notice board Friday at 3 PM." strip,
  the chosen option highlighted, all options disabled (`aria-disabled`).
- **Results (Friday 3 PM to Monday)**: kicker "PLOT TWIST · REVEALED FRI 11 SEP, 3 PM",
  the same bars as the Notice board but bigger, meta "12 voted · nobody, not even the
  Director, can see who picked what · next poll opens Monday".
- **No poll this week**: the card says "No plot twist this week. Suggest one below."
- **Opt-out strip** (only the person a Who question names, only before `opens_on`):
  amber strip above the hero with the question and an "Opt out" ghost button
  (`POST /app/plot-twist/{poll}/opt-out`).
- Faint 🎲 top-right of the hero; gone in plain mode. Plain kicker: "Weekly poll".

Below the hero, a "Got a better question?" card for everyone: text + kind select
(Fun / Who / Social) + Suggest (`POST /app/plot-twist/suggest`). Success swaps the row
for "Sent to HR. Thanks."

```css
.uj-pt-wrap { display:flex; flex-direction:column; gap:14px; max-width:720px; }
.uj-pt-hero { padding:22px 24px; display:flex; flex-direction:column; gap:10px; position:relative; overflow:hidden; }
.uj-pt-k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
.uj-pt-q { font-size:22px; font-weight:600; color:var(--ink); line-height:1.25; }
.uj-pt-meta { font-size:12px; color:var(--muted); }
.uj-pt-opts { display:flex; flex-direction:column; gap:8px; margin-top:4px; }
.uj-pt-opt { display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid var(--hairline); border-radius:10px; background:#fff; cursor:pointer; font-size:14px; color:var(--ink); text-align:left; transition:border-color 160ms ease, transform 160ms ease; }
.uj-pt-opt:hover { border-color:var(--red); transform:translateX(2px); }
.uj-pt-opt .dot { width:18px; height:18px; border-radius:50%; border:2px solid var(--hairline); flex-shrink:0; }
.uj-pt-opt.is-on { border-color:var(--red); background:#fdf2f2; }
.uj-pt-opt.is-on .dot { border-color:var(--red); background:var(--red); box-shadow:inset 0 0 0 3px #fff; }
.uj-pt-voted { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:10px; background:var(--info-tint); color:var(--info-ink); font-size:13px; }
.uj-pt-art-big { position:absolute; right:18px; top:14px; font-size:44px; opacity:.18; transform:rotate(12deg); }
.uj-pt-optout { padding:14px 18px; display:flex; align-items:center; gap:12px; background:#fff7e6; border:1px solid #e0b25a; border-radius:12px; font-size:13px; color:var(--ink); }
```
(Screen bars reuse `.uj-pt-bars` / `.uj-pt-bar` from section 1 with 170px label column, 10px bars, 14px text.)

## 3. HR publish form (`screen-hr.png`)

HR and Director only, on the same screen between the hero and the suggest card:
question bank chips (approved suggestions + the seeded template list, click fills the
question), Question, Kind (Fun / Who / Social), Opens on (Mondays only, the date input
rejects anything else with the normal `$errors` line), Named person (only when Kind is
Who, with the heads-up note), 2 to 6 option inputs, "Publish for Mon 14 Sep" +
"Save as draft". Who questions can only be picked from the template chips, free text is
refused for Kind = Who.

## Shazwan's note on approval (2026-09-09)
The buttons in the screenshots looked off (bare `.uj-btn-primary` / `.uj-btn-ghost`
have no height or padding, so "Suggest" and "Publish for Mon 14 Sep" rendered as
cramped text). Build them with explicit sizing, matched to the inputs beside them:

```css
.uj-pt-wrap .uj-btn-primary, .uj-pt-wrap .uj-btn-ghost { height:36px; padding:0 16px; font-size:13px; display:inline-flex; align-items:center; white-space:nowrap; flex-shrink:0; }
```
