# TOT: drawer, PIC strip and roster — design

**Date:** 2026-07-29
**Surface:** `/app/tot`, plus a new `/app/tot-roster`
**Mode:** Operate — the visitor completes a task; scanability outranks expression
**Prototype:** `public/_proto-tot.html` (delete once the port lands)
**Follows:** [2026-07-28-tot-panel-and-assign-permission-design.md](2026-07-28-tot-panel-and-assign-permission-design.md), which shipped the `tot.assign` permission, the icon row and the fetch-based actions.

## Why

Four things, three asked for and one found while building.

1. **Comments live in a centre modal.** The T.A.A. board settled on a right slide-over for exactly this job. Two detail surfaces with different grammar in one app is one too many.
2. **The PIC has no home.** A person presenting in March has to remember it is March, find the row and open it. Nothing on the board points at their month.
3. **Assigning a year is twelve separate forms.** Each month is opened, a presenter picked from a `<select>`, saved, and the page reloads. There is also no way to reach a year that has no rows yet.
4. **A non-TOT month and a skipped month differ only by opacity**, `.72` against `.45`. That never says which kind of month it is, and dimming a whole row drags its text under the contrast floor. Measured on the rendered page: the skipped row's name sits at **1.65:1** and the non-TOT subline at **2.99:1**, against a 4.5:1 minimum.

## Decisions

All confirmed with the user before this spec was written.

| Decision | Choice |
|---|---|
| View split | One board for everyone, plus one separate roster route |
| Drawer scope | The whole session; the accordion is deleted |
| Drawer grammar | Right slide-over, 560px, matching the T.A.A. drawer |
| Save model | Keep the `Save slot` button; no autosave |
| Assignment UX | Auto-advancing cursor — the WhatsApp picker |
| Adding a year | A link to `?year=<next>`; no rows created |
| Non-TOT months | Distinguish by shape, no `activity_type` column |
| `--muted-soft` | Leave the token alone; use a different token at the call sites |
| Mobile | Participate yes, author no |
| Undo | Emoji, watched and score all toggle |
| Masthead contrast | **KIV** — see "Out, handed off" |

## Scope

**In:** the drawer, the PIC strip, the status treatment, the roster screen, the mobile split, the two undo endpoint changes, and the CSS move out of the page-level `<style>` block.

**Out, deliberately:**

- An `activity_type` column with a colour per kind of event. One `not_tot` row exists in the whole database. Revisit when there are enough non-TOT months to see which categories are real.
- Threaded replies. Comments stay flat, as the previous spec decided.
- Mentions in the TOT thread. The T.A.A. board is getting them; TOT can adopt the same parser later.
- Changing who may read scores.

**Out, handed off:**

- **`--muted-soft` as a text colour.** It measures **2.92:1 to 3.55:1 on every surface in the app** and can never carry text. This spec does not redefine the token, because it is used well beyond TOT. It swaps the token at TOT's own call sites only, which is the user's explicit instruction.
- **The masthead.** `.tot-mast-k` is `rgba(255,255,255,.92)` on `--red` and composites to **4.48:1**, missing by 0.02. `.tot-yr` unselected is `rgba(255,255,255,.72)` and composites to **3.21:1**. Neither can be fixed by tuning alpha: full white on that red is only 5.08:1, so every "dimmer white" that still reads as dimmer is under 4.5:1 — the hierarchy has to move to weight. The prototype shows that fix. **The port does not carry it.** KIV per the user.

## Architecture

### Files

| File | Change |
|---|---|
| `resources/views/screens/tot.blade.php` | Accordion and centre modal removed. Row opens the drawer. PIC strip added. Page-level `<style>` block deleted. |
| `resources/views/partials/tot-drawer.blade.php` | **New.** The session detail surface. |
| `resources/views/screens/tot-roster.blade.php` | **New.** The roster picker. |
| `resources/css/app.css` | `.tot-row`, `.tot-tile`, `.tot-nm`, `.tot-sb`, `.tot-mast*`, `.tot-av` and the rest of the page-level block move here. `.tot-kick`, `.tot-pic` and `.tr-*` added. **`.wd-*` is not added — it already exists.** `.tot-modal*` removed. |
| `resources/js/tot-card.js` | `modalOpen` becomes drawer state. `rate()` and `toggleWatched()` handle the cleared case. |
| `resources/js/tot-roster.js` | **New.** Cursor, assignment, search. |
| `app/Http/Controllers/TotController.php` | `watched()` becomes a toggle. `rate()` accepts a null score. `rosterData()` added. |
| `app/Http/Controllers/AppController.php` | One match arm for `tot-roster`. |
| `app/Support/Amanahku.php` | Screen meta for `tot-roster` (title, sub, crumb). **No nav entry** — see below. |
| `app/Support/Features.php` | `tot-roster` added to the `module.knowledge` screen list, so disabling the module hides it too. |

**No migration.** See "Data".

### Routes and access

| Path | Who | Enforcement |
|---|---|---|
| `/app/tot` | everyone | unchanged |
| `/app/tot-roster` | `canAssignPresenter()` — HR, management, director, and `tot.assign` holders | `abort_unless` at the top of `rosterData()` |

Both resolve through the existing `/app/{screen?}` catch-all.

**The roster gets no sidebar entry.** `BuildsNav` supports a per-node `roles` allowlist, but the roster's audience is not a role — a `tot.assign` holder is usually a `manager`. A `roles` list would hide the screen from exactly the person the permission was created for, while leaving it reachable by URL. Instead the TOT masthead renders an **Edit roster** link when `canAssignPresenter` is true. One permission check, in the controller that already owns it, and no second gating mechanism to keep in sync.

### The board

The masthead, the year picker, the four chips and the twelve rows stay as they are.

**The accordion is deleted.** A row opens the drawer. This also removes the `overflow:visible` delay hack at `tot.blade.php:158`, which existed only because the rating flyout opened upward past a clipping ancestor. No clipping ancestor, no hack. The chevron turns to point right.

**The PIC strip.** A slim band under the masthead, rendered only when the signed-in employee presents a slot in the displayed year:

> **You present in February.** No topic yet — Saturday 7 February 2026.  · `Open my slot`

The button opens that month's drawer. This is what answers "a PIC view": somebody who presents once a year does not need a route, they need the board to point at their month.

**Status treatment.** `opacity` is removed from the row entirely and replaced by shape.

| Status | Tile | Text | Kicker |
|---|---|---|---|
| `not_tot` | `--shelf` fill, `--shelf-line` border, labels in `--body` | full strength; the event title is the row name | `Event` |
| `skipped` | no fill, 1px dashed `--hairline`, labels in `--muted` | `--muted`, weight 500 | `Skipped` |
| everything else | unchanged | unchanged | none |

Two rules behind this. First, a non-TOT month is a real thing that happened, so it reads at full strength; a skipped month is an absence, so it reads as an outline. Second, neither state is carried by colour alone — the kicker says it in words.

`--body` rather than `--muted` on the event tile because `--muted` is 4.86:1 on the canvas but **4.33:1 on `--shelf`**, which is the one surface it misses on. `app.css` already carries a comment saying so.

### The drawer

560px, right-anchored, `max-width:94vw`, full width below 600px. Same geometry, easing and scrim as the T.A.A. drawer, so the app ends up with one slide-over and not two.

That 600px is not a number this spec chooses — `app.css:893` already sets it, and the port must leave it alone. Editing a rule the board drawer also uses, to satisfy a breakpoint invented here, is the same mistake as redeclaring `.tot-fly`.

Top to bottom: sticky header with the month and a close button; presenter block with avatar, name and full date; the topic as a heading; property rows for status, presenter and material; description; links; the reaction pills; the action row; then a rule, the anonymous rater notes where permitted, and the thread. The composer docks to the panel floor.

**The speech bubble is removed from the action row.** The thread is in the same panel now, so a button that scrolls to something already on screen is noise. The heart, eye and star keep their flyouts.

**Read-only reads at full strength.** An employee sees the topic, status and links as plain text, not greyed inputs. It is the session's truth, not disabled chrome; only the affordance goes away.

**The Save button stays.** `POST /app/tot/{session}` is a whole-form endpoint. Autosave would mean reworking it for partial writes plus a sequence guard, and a slot is edited by one person roughly once a month. The board card earned autosave because it is edited constantly; this is not that.

**Keyboard and focus.** `role="dialog"`, `aria-modal="true"`, focus trapped, Escape closes the drawer unless a flyout is open, in which case it closes only the flyout. Focus returns to the row that opened it.

**Reuse, not restatement.** This is the single biggest thing the port can get wrong, and it now applies twice.

`app.css` already owns `.tot-actions`, `.tot-act`, `.tot-fw`, `.tot-fly`, `.tot-fly-e`, `.tot-pill`, `.tot-sc` and `.tot-fly-rate`, including two non-obvious fixes: `.tot-fly-rate { width:300px }` because an absolutely positioned box shrink-to-fits against its containing block, and a `max-width:480px` rule that turns the flyout into a bottom sheet. **The port must not redeclare these.** An early draft of the prototype did, and reproduced the exact bug the comment above `.tot-fly-rate` warns about.

**The T.A.A. board has since shipped its drawer**, so `app.css` now also owns the full `.wd-*` vocabulary — 64 rules covering `wd-scrim`, `wd-head`, `wd-body`, `wd-foot`, `wd-title`, `wd-sub`, `wd-props`, `wd-plabel`, `wd-pval`, `wd-sech`, `wd-rule`, `wd-cmts`, `wd-cmt` and its parts, `wd-post`, `wd-ico`, `wd-locked` and more. The TOT drawer is built from these, not from new CSS. In particular:

- **`.wd-locked` is the authoring note.** It is already "a read-only card says why, instead of greying out in silence" — a grey box with an icon and a sentence. The mobile "editing needs a wider screen" message is the same component with different copy. Do not add a `.wd-authnote`.
- **`.wd-inline--empty`** covers the "Nobody yet" / "No topic yet" empty states.

The genuinely new CSS in this change is small: `.tot-kick`, `.tot-pic`, the two `.tot-row[data-kind]` blocks, and `.tr-*` for the roster. If the port is adding a `.wd-` rule, it is probably wrong — grep first.

Note in passing, not to fix here: `.wd-inline--empty` uses `--muted-soft`, so it inherits the contrast problem listed under "Out, handed off". It arrived with the T.A.A. port and is not this change's to solve.

Avatars use `.tot-av` (28px, 11px initials), not `.wa`. `.wa` is the 20px overlapping stack chip with 9px initials, and borrowing it would both collide and import a known undersized-text problem.

### The roster

Two columns: twelve month slots on the left, the employee grid on the right.

**The cursor is the whole mechanism.** It sits on the next empty month. Clicking a person assigns them there, gives them a numbered badge, and moves the cursor to the next empty month, wrapping at December.

The cursor skips `not_tot` months and only those. A `not_tot` month is a deliberate "there is no TOT this month" marker, so it takes no presenter. A `skipped` month stays assignable, because correcting a month that was wrongly left empty should not require HR to change its status first. Assigning a presenter does not itself change any status.

Filling a fresh year is twelve clicks. To correct one month, click that month to move the cursor, then click the person: two clicks. One mechanism covers the bulk fill and the single edit.

Clearing is the `×` on the slot, which also moves the cursor there. Clicking a person always assigns to the cursor; it never toggles, because a person may hold two months and "which one did you mean" has no good answer.

**Every pick writes immediately** — `POST /app/tot` for a month with no row, `POST /app/tot/{session}` for one that has. There is no bulk Save, because a half-filled roster is a valid state and the person filling it stops halfway more often than not. A failed write rolls the slot back and raises the existing toast.

Assignment reuses `announcePresenter()`, so each newly assigned person gets one notification and re-saving the same person sends none. Filling twelve months notifies twelve different people, which is correct.

**Adding a year costs nothing.** `session_date` is computed rather than stored (`TotSession::firstSaturday`), `screenData()` already synthesises twelve placeholder slots for any year, and `availableYears()` already pushes the requested year into the picker. So **+ 2027** is a link to `?year=2027`. The year sticks in the picker as soon as the first person is assigned, and a year nobody touched correctly disappears again.

### Mobile

**Participate yes, author no.**

The phone keeps react, rate, mark-watched and comment. This is deliberate: the TOT reminder arrives as a notification, and notifications are opened on phones. Gating participation behind a desk would suppress the thing the reminders exist to cause.

The phone loses authoring: the PIC edit form, the presenter and status controls, and the roster.

Three breakpoints are in play. Two are this spec's, one already exists:

- **900px** gates authoring. Set by the roster, which is genuinely two columns and whose cursor depends on seeing both at once. The edit form rides the same number so there is one rule to state and one to test.
- **640px** is the phone layout, matching the breakpoint already in the live screen. The drawer goes full width. `.tot-meta` moves under the name as `.tot-meta-mobile`, which the live screen already has. `.tot-act` gains a 40px minimum height and the score circles go 30px → 40px, because `app.css` gives `.tot-act` `padding:0` and no height, which is a fine mouse target and a poor thumb one.
- **480px** is **not ours.** `app.css:753` already turns the flyouts into a bottom sheet there. The port adds nothing for the flyouts on mobile and must not try to re-anchor them.

**The authoring gate is a policy line, not a fitting problem, and the spec should not pretend otherwise.** The edit form lives inside the 560px drawer, so it is the same width on a phone and would fit. It is gated because setting a roster or writing up a topic is deliberate work.

Therefore **it must never fail silently**. Below 900px the `Edit my slot` button is replaced by a sentence saying where it went, and the roster route renders an explanatory card rather than an empty screen. A person on a half-width laptop window gets an explanation, not a mystery.

### Undo — server changes

The user asked that pressing a lit heart, eye or score take it back. Only one of the three already works.

| Action | Today | Change |
|---|---|---|
| `react` | Deletes an existing reaction (`TotController.php:325`) | **None.** Undo already works. |
| `watched` | `$row->watched_at ??= now()` — one-way | Becomes a real toggle |
| `rate` | `'score' => ['required', …]` | Accepts a null score |

**`watched()`** sets `watched_at` to `null` when it is already set, and to `now()` otherwise.

Un-watching leaves any score alone. They are separate columns recording separate facts, and a person who rated and then un-marked watched has made a self-inflicted, harmless inconsistency. Refusing the un-watch, or silently clearing their score, would both be worse surprises.

**`rate()`** changes its rule to `['present', 'nullable', 'integer', 'min:1', 'max:5']`. A null score clears `score` **and** `note`, because a note with no score is orphaned and the presenter would read it with nothing to read it against. `watched_at` survives, because you did watch it.

One guard: if the score is null **and** no participation row exists, return the current state without saving. Without it, clearing a rating you never gave would create a row and mark you as watched.

`visibleScores()` already filters `whereNotNull('score')`, so a cleared rating correctly drops out of the average, the count and the note list with no change.

## Data

**No migration.** Nothing in this change adds, drops or alters a column.

This is worth stating plainly because three parts of it look like they need one and do not: adding a year needs no rows because `session_date` is computed; distinguishing non-TOT months needs no `activity_type` because the treatment is presentational; and clearing a rating needs no schema change because `score` and `note` are already nullable.

## Testing

**Undo**

- `POST /app/tot/{session}/watched` twice leaves `watched_at` null.
- Un-watching a session the caller has rated leaves `score` intact.
- `rate` with `score: null` clears both `score` and `note` and leaves `watched_at` set.
- `rate` with `score: null` and no existing row creates no row.
- A cleared score leaves the session's average and count computed from the remaining raters, and drops that rater's note from the notes list.
- `rate` with `score: 6` or `score: 0` still fails validation.
- The existing concurrent double-rate test keeps passing.

**Roster**

- A `tot.assign` holder loads `/app/tot-roster`; an employee gets 403.
- Assigning a presenter to a month with no row creates it as `planned`.
- Assigning to a month that already has a row updates only the presenter.
- Assigning sends one notification; re-assigning the same person sends none.
- A `not_tot` month is not assignable.
- Every presenter write appends an audit row, as the previous spec requires.
- `/app/tot-roster?year=2027` renders twelve slots with no rows in the database.

**Board and drawer**

- The PIC strip renders only for an employee who presents in the displayed year.
- A viewer who may not see scores gets no score keys in the drawer payload.
- The drawer route returns only that session's comments and refuses a session from another tenant.
- `tot-roster` is hidden when `module.knowledge` is disabled.

Run with `php artisan test --compact --filter=Tot`.

**Browser verification, per stage, at 1280px and 375px:** no console errors, the drawer traps focus, the roster cursor skips a `not_tot` month, and the authoring note appears below 900px instead of the edit button.

A note on method for whoever does this: the in-app browser pane misreports computed styles immediately after a resize. Verify by loading fresh at each width, not by resizing and re-reading. Two phantom contrast failures during this design were stale cascade reads.

## Implementation stages

Four stages, sequential. Each leaves the screen working and is reviewed before the next.

**Stage 1 — CSS move and status treatment.** Move the page-level `<style>` block into `app.css`. Add `.tot-kick` and the `data-kind` rules. Swap `--muted-soft` for `--muted`, and for `--body` on the shelf tile, at TOT's call sites only. Remove `opacity` from the rows. No behaviour change.

**Stage 2 — the drawer.** Add `partials/tot-drawer.blade.php` built from the `.wd-*` classes the T.A.A. port already shipped. Point the row at it. Delete the accordion, the centre modal, `.tot-modal*` from `app.css`, and the `overflow` hack. Add the PIC strip. Reuse the existing `.tot-*` flyout classes and `.wd-locked` without redeclaring anything.

**Stage 3 — undo.** The two endpoint changes, their tests, and the client handling for a cleared score.

**Stage 4 — the roster.** `tot-roster.blade.php`, `tot-roster.js`, `rosterData()`, the `AppController` arm, the `Features` entry, the screen meta, and the **Edit roster** link on the masthead.

Finally, delete `public/_proto-tot.html` once the port is verified.

This is housekeeping, not a release gate: `.gitignore:49` matches `public/_*.html`, so no prototype is tracked and none can reach `main`. (The T.A.A. spec calls its prototype "a tracked path" — that is not correct, and no prototype has ever been committed.) Deleting it matters only so the next person does not read a stale mock as current.

## Risks

| Risk | Mitigation |
|---|---|
| The port redeclares `.tot-fly` / `.tot-sc` and reintroduces the shrink-to-fit bug | Stage 2 reuses them; grep `app.css` for each `.tot-*` class before adding it |
| Deleting `.tot-modal*` breaks another screen | Verified TOT-only: `app.css:952-969` defines it and `tot.blade.php:515` is the sole reference. Safe to delete in Stage 2 |
| The roster's immediate writes leave a half-assigned year after a failed request | Each slot rolls back independently and toasts; a partial roster is a valid state |
| Un-watch and score drift out of agreement | Accepted and documented above; no enforcement |
| Moving the `<style>` block changes another screen that relied on a leaked rule | The block is inside `@section('screen')` for TOT only, but verify the four `.tot-*` classes that also exist in `app.css` do not now double-apply |
| The prototype outliving the port and being read as current | Deleted in Stage 4. It cannot reach `main` — `.gitignore:49` covers `public/_*.html` — so this is staleness, not leakage |
