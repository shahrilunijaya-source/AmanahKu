# S22 / CR-24 mockup (awaiting Shazwan's ok)

Screenshots made by injecting the markup into the live dashboard, Awards screen and
Projects screen, so the chrome is real. Files: `dash-big-deal.png` (full dashboard),
`dash-big-deal-band.png` (the band alone), `dash-big-deal-plain.png` (Keep it plain),
`dash-big-deal-phone.png`, `wins-page.png`, `projects-raise-form.png`.

## The banner

One more moment in the existing moments band (`partials/dash/bands.blade.php`,
`DashboardBands`), never a new band. Same red-tint `uj-db-moment` skin as the birthday
moment, wrapping rows like the birthday band does. Top row: kicker BIG DEAL ALERT, the
headline, the team avatars (overlapping, names in small text), then the one-liner on its own
row. Under it a quiet "What it took" block with a red left rule (the PM's story, a meta
line: raised by, project, Track ref, on the dashboard until). Then the photo strip (up to
three 76x56 thumbs) and the CR-30 reaction pills with the tally on the right. Confetti
drift on the right edge is the existing `.uj-db-art`, gone under Keep it plain and on
phones (existing rule).

```html
<section class="uj-db-band uj-db-moment" data-kind="big-deal" data-big-deal="1" aria-label="iLPF just completed UAT">
    <span class="uj-db-k">Big deal alert</span>
    <span class="uj-db-t">iLPF just completed UAT</span>
    <span class="uj-bd-team"><span class="uj-db-avatar" data-big-deal-member="5">KU</span>…<small>Kussairi, Shazwan, Nabil</small></span>
    <span class="uj-db-s">Everyone involved may now breathe again.</span>
    <div class="uj-bd-story"><b>What it took</b><p>…</p><i>Raised by Kussairi · iLPF · Track milestone MS-42 · on the dashboard until Sat 12 Sep</i></div>
    <div class="uj-bd-photos"><img src="/app/big-deals/1/photos/1" alt="">…</div>
    <div class="uj-bd-react">
        @include('partials.reaction-picker', …)          <!-- data-reaction-pick pills -->
        <span class="uj-react-tally"><span class="uj-react-chip" data-reaction-count="respect">🫡 <b>4</b></span></span>
    </div>
    <div class="uj-db-art">…</div>                        <!-- not under plain -->
</section>
```

```css
.uj-db-band[data-kind="big-deal"] { flex-wrap:wrap; row-gap:10px; }
.uj-db-band[data-kind="big-deal"] .uj-db-s { flex-basis:100%; margin-top:-6px; }
.uj-bd-team { display:flex; align-items:center; }
.uj-bd-team .uj-db-avatar { margin-left:-7px; border:2px solid #fff; box-shadow:0 0 0 1px var(--hairline); }
.uj-bd-team .uj-db-avatar:first-child { margin-left:0; }
.uj-bd-team small { margin-left:8px; font-size:11.5px; color:var(--muted); white-space:nowrap; }
.uj-bd-photos { display:flex; gap:8px; }
.uj-bd-photos img { width:76px; height:56px; object-fit:cover; border-radius:8px; border:1px solid var(--hairline); background:#fff; }
.uj-bd-story { flex-basis:100%; display:flex; flex-direction:column; gap:3px; padding:8px 12px; border-left:2px solid var(--red); background:rgba(255,255,255,.55); border-radius:0 8px 8px 0; }
.uj-bd-story b { font:600 11px var(--font-sans); letter-spacing:.08em; text-transform:uppercase; color:var(--muted-soft); }
.uj-bd-story p { margin:0; font-size:var(--t-sm); color:var(--body); line-height:1.45; }
.uj-bd-story i { font-style:normal; font-size:11.5px; color:var(--muted); }
.uj-bd-react { flex-basis:100%; display:flex; flex-wrap:wrap; gap:6px; align-items:center; position:relative; z-index:1; }
.uj-bd-react .uj-react-tally { margin-left:auto; }
.uj-db[data-plain] .uj-bd-story { background:none; border-left-color:var(--hairline); }
.uj-db[data-plain] .uj-db-band[data-kind="big-deal"] .uj-db-k { color:var(--muted-soft); }
@media (max-width:720px) { .uj-bd-photos img { width:64px; height:48px; } .uj-bd-react .uj-react-tally { margin-left:0; flex-basis:100%; } }
```

Keep it plain: same section, card background, grey kicker, no art, no confetti, pills
without their entrance animation (existing `.uj-db[data-plain] .uj-react-pick` rule).

## Raising one

A "Mark as Big Deal" ghost button in the project row's action strip (Projects screen,
next to History / Variations / Edit) for PM and above, and the same form behind a
"Mark as Big Deal…" item in the card drawer's ⋯ menu on the T.A.A. board. The form opens
inline under the row (same pattern as Variations): type select, Track ref, headline,
one-liner, What it took, team chips, up to three photos, one help line. Client compliment
adds a source file field and a "names approved" tick. Posting redirects back with the
usual `ok` flash.

## Wins

`GET /app/wins` under The Playground, on the Awards screen chrome: a project filter row
of ghost buttons and one card listing every Big Deal newest first (`[data-win]`): kicker
type + date + project, headline, one-liner, the story block, avatars, photo strip, tally.
Reactions stay read-only here in the mockup; the session may keep the picker if cheap.
