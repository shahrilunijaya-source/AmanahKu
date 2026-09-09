# S25 / CR-29 Friday Sign-Off: mockup for approval

One surface: the existing `friday` dashboard widget (S04 slot, left column after Pending
tasks, Friday 15:00 to Monday 09:00). Screenshots in this folder were produced by injecting
`fr-widget.js` into the live dashboard (`window.__state` = prompt | done | mood | few,
`window.__plain`). Shapes (route, tables, data attributes, the anonymity model) are fixed by
`tests/Acceptance/CR29Test.php`; this file only fixes how it looks. Copy is EN, BM follows
the usual `$store.ui.lang` pattern.

## States

1. **Prompt** (`widget-prompt.png`, `dash-prompt.png`, `widget-prompt-phone.png`): kicker
   "FRIDAY SIGN-OFF · CLOSES MON 9 AM", "This week was…", four mood tiles in a 2x2 grid
   (`button.uj-fr-mood[data-mood]`, one emoji each, `.is-on` on the pick), "My win this week
   (optional, one line)" text input (`name=win`, maxlength 160), "Share my win under my
   name" checkbox (`name=share`) and a "Sign off" primary button on one row, then the
   anonymity line. One tap on a tile plus Sign off posts `POST /app/friday-signoff`, the
   body swaps to state 2 in place (no reload).
2. **Done** (`widget-done.png`): green `.uj-fr-done[data-friday-done]` "Signed off. Company
   mood lands here at 5 PM once five people have answered." and the viewer's own win row
   (`[data-friday-my-win]`, "private" pill when not shared).
3. **Mood** (`widget-mood.png`), from Friday 17:00 with 5+ responses: kicker "COMPANY MOOD ·
   FRI 5 PM", "This week, Unijaya was…", one bar per mood (`.uj-fr-bar`, winner bold,
   `[data-mood-pct]` percentage, same bar look as the Plot Twist notice), "N signed off ·
   nobody can see who picked what", then shared wins under the author's first name
   (`[data-friday-win]`) and the viewer's private win with the pill. The cheeky
   "Refusing to Elaborate" is the non-plain display label for the "I Survived" bucket in
   the results bars only; the tile still says "I Survived".
4. **Few** (`widget-few.png`): same kicker, "Company mood needs 5 sign-offs before it
   shows. 4 so far." instead of bars; wins still listed.
5. **Plain** (`widget-prompt-plain.png`, `widget-mood-plain.png`): no emoji, kicker
   sentence-case, "Suspiciously Peaceful" becomes "Peaceful", "Refusing to Elaborate"
   becomes "I Survived", bars do not animate.

Between an answer and 17:00 the widget shows state 2. A viewer who has not signed off by
17:00 sees the mood bars with the prompt tiles above them (still one tap), until Monday.

## Markup

See `fr-widget.js` for the exact HTML per state.

## CSS

```css
.uj-fr { display:flex; flex-direction:column; gap:12px; }
.uj-fr-k { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--red); }
.uj-fr-q { font-size:16px; font-weight:600; color:var(--ink); line-height:1.3; }
.uj-fr-moods { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:8px; }
.uj-fr-mood { display:flex; align-items:center; gap:10px; padding:11px 12px; border:1px solid var(--hairline); border-radius:10px; background:#fff; cursor:pointer; font-size:13px; color:var(--ink); text-align:left; transition:border-color 160ms ease, transform 160ms ease; }
.uj-fr-mood:hover { border-color:var(--red); transform:translateY(-1px); }
.uj-fr-mood .uj-fr-art { font-size:18px; line-height:1; }
.uj-fr-mood.is-on { border-color:var(--red); background:#fdf2f2; }
.uj-fr-win { display:flex; flex-direction:column; gap:6px; }
.uj-fr-win label { font-size:12px; color:var(--muted); }
.uj-fr-win input[type=text] { width:100%; height:36px; padding:0 12px; border:1px solid var(--hairline); border-radius:8px; font-size:13px; color:var(--ink); background:#fff; }
.uj-fr-row { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.uj-fr-share { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--body); }
.uj-fr .uj-btn-primary { height:36px; padding:0 16px; font-size:13px; display:inline-flex; align-items:center; white-space:nowrap; flex-shrink:0; }
.uj-fr-done { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:10px; background:#e6f3ee; color:#1f6b4a; font-size:13px; }
.uj-fr-done b { font-weight:600; }
.uj-fr-bars { display:flex; flex-direction:column; gap:6px; }
.uj-fr-bar { display:grid; grid-template-columns:150px 1fr 38px; align-items:center; gap:8px; font-size:var(--t-sm); color:var(--body); }
.uj-fr-bar .bar { height:8px; border-radius:4px; background:var(--hairline-soft); overflow:hidden; }
.uj-fr-bar .bar i { display:block; height:100%; background:var(--red); border-radius:4px; width:var(--w); animation:uj-fr-grow 600ms var(--ease) both; }
.uj-fr-bar.win { font-weight:600; color:var(--ink); }
.uj-fr-bar .pct { text-align:right; font:600 var(--t-micro) var(--font-mono); color:var(--muted); }
@keyframes uj-fr-grow { from { width:0 } }
.uj-db[data-plain] .uj-fr-bar .bar i { animation:none; }
.uj-fr-meta { font-size:12px; color:var(--muted); }
.uj-fr-wins { display:flex; flex-direction:column; gap:8px; border-top:1px solid var(--hairline-soft); padding-top:12px; }
.uj-fr-winrow { font-size:13px; color:var(--ink); display:flex; gap:8px; align-items:flex-start; }
.uj-fr-winrow .who { font-weight:600; white-space:nowrap; }
.uj-fr-winrow .priv { font-size:11px; color:var(--muted); border:1px solid var(--hairline); border-radius:999px; padding:1px 7px; white-space:nowrap; }
```
