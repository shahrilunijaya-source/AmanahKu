# S26 / CR-26 Side Quests: mockup for approval

Two surfaces. Screenshots were produced by injecting `sq-screen.js` over the live Wins
screen (`window.__plain`, `window.__hr`) and `sq-profile.js` on the profile page. Shapes
(routes, tables, data attributes) are fixed by `tests/Acceptance/CR26Test.php`; this file
only fixes how it looks. Copy is EN, BM follows the usual `$store.ui.lang` pattern.

## 1. Playground screen `/app/side-quests` (`screen.png`, `screen-hr.png`, `screen-plain.png`, `screen-phone.png`)

Sidebar entry "Side Quests" under The Playground, guide line "Small optional challenges
with nothing to do with KPI. Finish one, post it, wear the badge." Top to bottom:

- Kicker `.uj-sq-k` "NOT A KPI. NEVER WILL BE." (plain: "Optional challenges") and a
  one-line explainer.
- **Quest cards** `.uj-sq-quests` grid of the live quests (`.uj-card.uj-sq-quest[data-quest]`):
  emoji art `.uj-sq-art` (dropped when plain), title, blurb, "I did this" primary
  button. A quest the viewer already completed is `.is-done` (green tint) with "✓ Done ·
  badge until <date>" instead of the button. HR/director see a small "Retire" text button
  top-right on each card.
- **Proof form** `.uj-card.uj-sq-form[data-quest-complete]` appears under the grid when
  "I did this" is pressed (Alpine toggle, no reload): a two-row textarea `name=note`
  (maxlength 280), a dashed "Add a photo" file chip `name=photo`, "Post it" primary. Posts
  as multipart to `POST /app/side-quests/{quest}/complete`, then the page reloads
  (feed and badge are server-rendered).
- **Suggest row**: staff see one input "Suggest a quest for HR to pick up" + ghost
  "Suggest" (`POST /app/side-quests/suggest`). HR/director instead see the
  **Suggested by staff** card (`[data-quest-suggestion]` rows with avatar, title, who,
  "Make it live" ghost button) and a "New quest title" + "Publish" row.
- **Feed** `.uj-sq-feed`, newest first, `.uj-card.uj-sq-post[data-quest-post]`: avatar,
  name, quest title as a red uppercase tag, date right-aligned, the one-liner, the photo
  (max 320px wide, rounded) when posted, the CR-30 reaction row (`partials.reaction-picker`
  + tally, same as Wins).

## 2. Profile badge (`profile-badge.png`)

`.uj-sq-badge[data-quest-badge]` pill (purple tint, 🏷️ art dropped when plain) with the
quest title and "· until <date>", rendered next to the CR-14b award badges on the profile
card (`partials/awards/badges.blade.php` slot), on own profile and on `?emp=`. Gone after
`expires_at`.

## CSS

```css
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
```
