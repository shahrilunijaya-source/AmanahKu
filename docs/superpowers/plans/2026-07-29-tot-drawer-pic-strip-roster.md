# TOT drawer, PIC strip and roster — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the TOT accordion and comment modal with a right slide-over, point presenters at their own month, let assigners fill a year with a click-per-month picker, and make every reaction undoable.

**Architecture:** The board keeps its twelve rows but opens a 560px drawer built entirely from the `.wd-*` classes the T.A.A. board already shipped into `app.css`. A second screen, `/app/tot-roster`, holds a cursor-driven assignment picker. Two controller actions gain the ability to undo. Nothing here changes the database schema.

**Tech Stack:** Laravel 13, PHP 8.5, Blade, Alpine 3, Tailwind 4 (utilities only — this screen uses hand-written CSS in `app.css`), PHPUnit 12, Vite via `bun`.

**Spec:** [docs/superpowers/specs/2026-07-29-tot-drawer-pic-strip-roster-design.md](../specs/2026-07-29-tot-drawer-pic-strip-roster-design.md)

## Global Constraints

- **No migration.** No task adds, drops or alters a column. If you reach for one, stop and re-read the spec.
- **Never redeclare an existing CSS class.** `app.css` already owns `.tot-actions`, `.tot-act`, `.tot-fw`, `.tot-fly`, `.tot-fly-e`, `.tot-fly-rate`, `.tot-pill`, `.tot-sc`, and 64 `.wd-*` rules. Grep `resources/css/app.css` before adding any rule. Redeclaring `.tot-fly` reintroduces a fixed bug.
- **Do not use `--muted-soft` for text.** It measures 2.92:1–3.55:1 on every surface. Use `--muted` (4.86:1 on `--canvas`), or `--body` on `--shelf`, where `--muted` drops to 4.33:1.
- **Do not touch the masthead contrast.** `.tot-mast-k` and `.tot-yr` are known-failing and explicitly deferred (KIV). Leave them exactly as they are.
- **Run Pint before every commit:** `vendor/bin/pint --dirty --format agent`
- **Tests run on the host**, sqlite in-memory, no container: `php artisan test --compact --filter=<Name>`
- **Bilingual copy.** Every user-facing string needs an `x-text="$store.ui.lang==='en' ? … : …"` pair, matching the existing screen.
- The prototype at `public/_proto-tot.html` is the visual reference. It is gitignored. Delete it in Task 9.

---

### Task 1: Status treatment and the CSS move

Move TOT's page-level `<style>` block into `app.css` and make a non-TOT month look different from a skipped one by shape instead of opacity.

**Files:**
- Modify: `resources/views/screens/tot.blade.php` (delete the `<style>` block at lines 108–214; change the row markup)
- Modify: `resources/css/app.css` (append the moved block plus the new rules)
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `data-kind="event"` and `data-kind="skipped"` attributes on `.tot-row`, and a `.tot-kick` span. Task 2 relies on the row markup staying a `<button>` that opens something.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TotTest.php`:

```php
public function test_a_non_tot_month_and_a_skipped_month_are_told_apart_by_kind(): void
{
    TotSession::create([
        'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 4,
        'title' => 'Jamuan raya', 'status' => 'not_tot',
    ]);
    TotSession::create([
        'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 9,
        'status' => 'skipped',
    ]);

    $response = $this->actingInTenant()->get('/app/tot?year=2026');

    $response->assertSee('data-kind="event"', false)
        ->assertSee('data-kind="skipped"', false);
}

public function test_no_row_dims_itself_with_opacity(): void
{
    TotSession::create([
        'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 9,
        'status' => 'skipped',
    ]);

    $this->actingInTenant()->get('/app/tot?year=2026')
        ->assertDontSee('style="opacity:', false);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotTest`

Expected: FAIL. The first test cannot find `data-kind="event"`; the second finds `style="opacity:0.45"` on the skipped row.

- [ ] **Step 3: Move the style block into app.css**

Cut lines 108–214 of `resources/views/screens/tot.blade.php` — the entire `<style>…</style>` element — and paste its contents at the end of `resources/css/app.css` under this heading:

```css
/* ── TOT year lineup ───────────────────────────────────────────
   Moved out of the page-level <style> in screens/tot.blade.php so
   the drawer and the roster can share it. The reaction pills, the
   icon row and the flyouts are NOT here — they already live above.
   ─────────────────────────────────────────────────────────── */
```

Then make three edits to the moved block:

1. In `.tot-sb`, change `color:var(--muted-soft)` to `color:var(--muted)`. Delete the `.tot-sb[data-tone="soft"]` rule; it is the same colour now and no longer earns a name.
2. Delete `.tot-nm[data-tone="soft"]` and `.tot-nm[data-tone="muted"]`.
3. Leave `.tot-mast-k` and `.tot-yr` byte-for-byte unchanged. They are KIV.

- [ ] **Step 4: Add the new rules**

Append to `resources/css/app.css`, directly after the moved block:

```css
/* A non-TOT month and a skipped month used to differ only by opacity, .72
   against .45. That never said which kind of month it was, and dimming a whole
   row dragged its text under the contrast floor: measured on the rendered page
   the skipped name sat at 1.65:1 against a 4.5:1 minimum. Opacity is gone and
   the two states take different shapes instead. */

/* not_tot — a real calendar entry that was not a TOT, so it reads at full
   strength. --body rather than --muted on the tile because --muted is 4.86:1 on
   the canvas but only 4.33:1 on --shelf, the one surface it misses. */
.tot-row[data-kind="event"] .tot-tile { background:var(--shelf); border:1px solid var(--shelf-line); }
.tot-row[data-kind="event"] .tot-tile .m,
.tot-row[data-kind="event"] .tot-tile .d { color:var(--body); }
.tot-row[data-kind="event"]:hover { background:var(--shelf); }
.tot-row[data-kind="event"]:hover .tot-tile { background:var(--shelf-line); border-color:var(--muted-soft); }
.tot-row[data-kind="event"]:hover .tot-tile .m,
.tot-row[data-kind="event"]:hover .tot-tile .d,
.tot-row[data-kind="event"]:hover .tot-nm { color:var(--ink); }

/* skipped — an absence, so it reads as an outline. The dashed tile carries the
   "nothing happened"; the text does not have to go pale to say it, which was
   the same mistake the opacity made one step smaller. */
.tot-row[data-kind="skipped"] .tot-tile { background:none; border:1px dashed var(--hairline); }
.tot-row[data-kind="skipped"] .tot-tile .m,
.tot-row[data-kind="skipped"] .tot-tile .d { color:var(--muted); }
.tot-row[data-kind="skipped"] .tot-nm { color:var(--muted); font-weight:500; }
.tot-row[data-kind="skipped"]:hover { background:var(--hairline-soft); }
.tot-row[data-kind="skipped"]:hover .tot-tile { background:none; border-color:var(--muted-soft); transform:none; }
.tot-row[data-kind="skipped"]:hover .tot-tile .m,
.tot-row[data-kind="skipped"]:hover .tot-tile .d,
.tot-row[data-kind="skipped"]:hover .tot-nm { color:var(--ink); }

/* The kicker names the kind in words, so shape is never the only carrier.
   11px, not 10: a letterspaced micro-label is still functional text, and 11px is
   the floor this file already holds everywhere else (.tot-up, .tot-mast-k). */
.tot-kick { display:inline-block; font-size:11px; font-weight:600; letter-spacing:.12em;
  text-transform:uppercase; color:var(--muted); margin-bottom:3px; }
```

- [ ] **Step 5: Update the row markup**

In `resources/views/screens/tot.blade.php`, inside the `@php` block, replace the `$opacity` computation with a `$kind` one:

```php
$kind = match ($session->status) {
    'not_tot' => 'event',
    'skipped' => 'skipped',
    default => null,
};
$kickEn = match ($kind) { 'event' => 'Event', 'skipped' => 'Skipped', default => null };
$kickMs = match ($kind) { 'event' => 'Acara', 'skipped' => 'Dilangkau', default => null };
```

Store `'kind' => $kind, 'kickEn' => $kickEn, 'kickMs' => $kickMs` in `$rowMeta[$i]` and delete the `'opacity'` key.

Also change the two name/subline defaults so a skipped month reads as the absence rather than as an absent person:

```php
$subline = match (true) {
    $session->status === 'skipped' => 'Nobody was assigned',
    $session->status === 'not_tot' => $session->title,
    // …the rest is unchanged
};
$nameText = $session->status === 'skipped'
    ? 'No session held'
    : ($session->presenter?->name ?? $session->presenter_name
        ?? ($session->status === 'not_tot' ? $session->title : 'Nobody assigned'));
```

Add `'No session held' => 'Tiada sesi diadakan'` and `'Nobody was assigned' => 'Belum ada pembentang'` to the `$nameTextMs` / `$sublineMs` maps.

Then update the `<button class="tot-row">` opening tag and its name block:

```blade
<button type="button" class="tot-row" @if ($rm['kind']) data-kind="{{ $rm['kind'] }}" @endif
        @click="open = !open" :aria-expanded="open">
    <div class="tot-tile">
        <div class="m">{{ $session->session_date->format('M') }}</div>
        <div class="d">{{ $session->session_date->format('d') }}</div>
    </div>
    <div style="min-width:0;">
        @if ($rm['kickEn'])
            <span class="tot-kick" x-text="$store.ui.lang==='en' ? @js($rm['kickEn']) : @js($rm['kickMs'])">{{ $rm['kickEn'] }}</span>
        @endif
        <div class="tot-nm" x-text="$store.ui.lang==='en' ? @js($rm['nameText']) : @js($rm['nameTextMs'])">{{ $rm['nameText'] }}</div>
```

Delete `style="opacity:{{ $rm['opacity'] }}"`, the `@if ($session->status === 'skipped') data-tone="soft" @endif` on `.tot-tile`, and the `@if ($rm['nameTone']) data-tone=… @endif` on `.tot-nm`. Delete `$nameTone` from the `@php` block.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`

Expected: PASS, all tests in the file.

- [ ] **Step 7: Rebuild assets and check the screen**

```bash
bun run build
```

Open `http://localhost:9100/app/tot?year=2026` signed in via `http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya`. April should show a shelf-grey tile with an `Event` kicker and the title "Jamuan raya" at full strength. No row should look faded.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/css/app.css resources/views/screens/tot.blade.php tests/Feature/TotTest.php public/build
git commit -m "feat(tot): tell a non-TOT month apart from a skipped one by shape

Both were rendered as the same faded row, .72 opacity against .45, which
never said which kind of month it was and put the skipped row's text at
1.65:1 against a 4.5:1 minimum. A non-TOT month now reads at full strength
in a shelf tile with an Event kicker; a skipped one reads as a dashed
outline. Neither relies on colour alone.

The page-level style block moves to app.css so the drawer and the roster
can share it."
```

---

### Task 2: `watched` becomes a real toggle

**Files:**
- Modify: `app/Http/Controllers/TotController.php` (the `watched` method)
- Test: `tests/Feature/TotLiveActionsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `POST /app/tot/{session}/watched` now clears `watched_at` when it is already set. The JSON shape is unchanged — `sessionState()` still returns `watched` (int) and `iWatched` (bool).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TotLiveActionsTest.php`:

```php
public function test_watching_twice_takes_it_back(): void
{
    $session = $this->slot();

    $this->actingInTenant()->postJson("/app/tot/{$session->id}/watched");
    $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/watched");

    $response->assertOk()
        ->assertJsonPath('watched', 0)
        ->assertJsonPath('iWatched', false);

    $this->assertNull(
        TotParticipation::where('session_id', $session->id)->value('watched_at')
    );
}

public function test_un_watching_leaves_your_score_alone(): void
{
    $session = $this->slot();

    $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => 4]);
    $this->actingInTenant()->postJson("/app/tot/{$session->id}/watched");

    $row = TotParticipation::where('session_id', $session->id)->first();

    $this->assertNull($row->watched_at);
    $this->assertSame(4, $row->score);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotLiveActionsTest`

Expected: FAIL. `watched` stays 1 and `iWatched` stays true, because `watched_at ??= now()` never clears.

- [ ] **Step 3: Make it a toggle**

In `app/Http/Controllers/TotController.php`, in `watched()`, replace this line:

```php
        $row->watched_at ??= now();
```

with:

```php
        // A toggle, not a latch. Pressing a lit eye takes the mark back.
        // Any score on this row survives: they are separate facts, and silently
        // dropping somebody's rating because they un-marked watched would be a
        // worse surprise than the mild inconsistency of keeping it.
        $row->watched_at = $row->watched_at ? null : now();
```

Leave the `QueryException` catch exactly as it is. Its reasoning still holds: the only way to hit a unique violation here is a concurrent *insert*, and in that case the row the winner created is already `watched`, which is what a first press wanted.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotLiveActionsTest`

Expected: PASS, all tests in the file including the pre-existing `test_watching_returns_the_new_state`.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php tests/Feature/TotLiveActionsTest.php
git commit -m "feat(tot): let a watched mark be taken back

watched_at was set with ??=, so the eye was a latch: once lit it could
never be cleared, even though the icon reads as a toggle and the emoji
beside it already toggles. Any score on the row is left alone."
```

---

### Task 3: `rate` accepts a cleared score

**Files:**
- Modify: `app/Http/Controllers/TotController.php` (the `rate` method)
- Test: `tests/Feature/TotLiveActionsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `POST /app/tot/{session}/rate` accepts `{"score": null}`. The `score` key must be **present** in the request; omitting it is now a validation error.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TotLiveActionsTest.php`:

```php
public function test_rating_the_same_score_again_clears_it_and_its_note(): void
{
    $session = $this->slot();

    $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => 'Useful']);
    $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => null]);

    $response->assertOk()->assertJsonPath('myScore', null);

    $row = TotParticipation::where('session_id', $session->id)->first();
    $this->assertNull($row->score);
    $this->assertNull($row->note, 'a note with no score is orphaned');
    $this->assertNotNull($row->watched_at, 'you still watched it');
}

public function test_clearing_a_rating_you_never_gave_creates_no_row(): void
{
    $session = $this->slot();

    $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => null])
        ->assertOk()
        ->assertJsonPath('myScore', null)
        ->assertJsonPath('iWatched', false);

    $this->assertSame(0, TotParticipation::where('session_id', $session->id)->count());
}

public function test_a_cleared_rating_drops_out_of_the_average_and_the_notes(): void
{
    $session = $this->slot();
    $session->update(['presenter_employee_id' => $this->employee->id]);

    $other = Employee::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Other',
        'status' => 'active', 'workload' => 'green',
    ]);
    TotParticipation::create([
        'session_id' => $session->id, 'employee_id' => $other->id,
        'score' => 2, 'note' => 'Theirs', 'watched_at' => now(),
    ]);

    $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => 4, 'note' => 'Mine']);
    $response = $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => null]);

    $response->assertOk()
        ->assertJsonPath('score.average', 2)
        ->assertJsonPath('score.count', 1);
}

public function test_an_out_of_range_score_is_still_rejected(): void
{
    $session = $this->slot();

    $this->actingInTenant()->postJson("/app/tot/{$session->id}/rate", ['score' => 6])
        ->assertStatus(422);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotLiveActionsTest`

Expected: FAIL with 422 on the clearing tests, because `score` is `required`.

- [ ] **Step 3: Accept a null score**

In `app/Http/Controllers/TotController.php`, in `rate()`, change the validation:

```php
        $data = $request->validate([
            // present + nullable, not nullable alone: clearing a rating must be an
            // explicit "score": null, so a request that merely forgets the key cannot
            // silently wipe somebody's score.
            'score' => ['present', 'nullable', 'integer', 'min:1', 'max:5'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
```

Then replace the three lines that set the row:

```php
        $row->score = $data['score'];
        if ($request->has('note')) {
            $row->note = $request->input('note') === '' ? null : $data['note'];
        }
        $row->watched_at ??= now();
```

with:

```php
        if ($data['score'] === null) {
            // Nothing to clear, and creating the row here would mark the caller
            // watched as a side effect of a no-op.
            if (! $row->exists) {
                return $request->expectsJson()
                    ? response()->json($this->sessionState($request, $session))
                    : back();
            }

            // The note goes with the score. A note with no score is orphaned, and
            // the presenter would read it with nothing to read it against.
            $row->score = null;
            $row->note = null;
        } else {
            $row->score = $data['score'];
            // The box is prefilled from the rater's own note, so a blank box now means
            // clear it, while a score-only submit from the flyout carries no note key
            // at all and leaves the note alone.
            if ($request->has('note')) {
                $row->note = $request->input('note') === '' ? null : $data['note'];
            }
            $row->watched_at ??= now();
        }
```

`visibleScores()` already filters `whereNotNull('score')`, so a cleared rating drops out of the average, the count and the note list with no further change.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotLiveActionsTest`

Expected: PASS, all tests in the file including the pre-existing concurrent-rate test.

- [ ] **Step 5: Run the full TOT suite for regressions**

Run: `php artisan test --compact --filter=Tot`

Expected: PASS. `TotTest`, `TotAssignPermissionTest`, `TotLiveActionsTest`, `TotHistorySeederTest` and `TotReminderTest` all green.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php tests/Feature/TotLiveActionsTest.php
git commit -m "feat(tot): let a rating be taken back

score was required, so a rating could be changed but never removed. It now
accepts an explicit null, which clears the score and the note together —
a note with no score is orphaned. watched_at survives, because you did
watch it, and clearing a rating you never gave creates no row rather than
marking you watched as a side effect."
```

---

### Task 4: Wire the undo into the client

**Files:**
- Modify: `resources/js/tot-card.js`

**Interfaces:**
- Consumes: the endpoints from Tasks 2 and 3.
- Produces: `rate(score)` sends `null` when the pressed score is already yours. `saveNote(note)` is a no-op with no score.

- [ ] **Step 1: Make `rate` toggle**

In `resources/js/tot-card.js`, replace:

```js
        rate(score) {
            return this.act(`/app/tot/${this.id}/rate`, { score });
        },

        saveNote(note) {
            return this.act(`/app/tot/${this.id}/rate`, { score: this.myScore, note });
        },
```

with:

```js
        // Pressing the score you already gave takes it back, matching the emoji
        // and the eye. The server clears the note along with the score.
        rate(score) {
            return this.act(`/app/tot/${this.id}/rate`, {
                score: this.myScore === score ? null : score,
            });
        },

        // With no score there is nothing for a note to annotate, and posting one
        // would send score:null and clear the row instead of saving the text.
        saveNote(note) {
            if (this.myScore === null || this.myScore === undefined) return;

            return this.act(`/app/tot/${this.id}/rate`, { score: this.myScore, note });
        },
```

- [ ] **Step 2: Build**

```bash
bun run build
```

Expected: build succeeds, `public/build/manifest.json` updated.

- [ ] **Step 3: Verify in the browser**

Sign in at `http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya`, open `http://localhost:9100/app/tot`, expand a `done` month, and:

- press the star, pick 4, reopen the flyout, press 4 again — the number should clear and the star should lose its active state;
- press the eye twice — the count should go up then back down;
- press an emoji twice — the pill should appear then disappear.

Check the browser console is clean.

- [ ] **Step 4: Commit**

```bash
git add resources/js/tot-card.js public/build
git commit -m "feat(tot): press a score again to remove it

Completes the undo set on the client. saveNote refuses to post without a
score, because that would send score:null and clear the row rather than
save the text."
```

---

### Task 5: The drawer replaces the accordion and the modal

**Files:**
- Create: `resources/views/partials/tot-drawer.blade.php`
- Modify: `resources/views/screens/tot.blade.php`
- Modify: `resources/css/app.css` (delete `.tot-modal*`, add `.tot-pic` and the mobile rules)
- Modify: `resources/js/tot-card.js` (rename the modal flag)
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: `data-kind` from Task 1; `totCard()` from Task 4.
- Produces: the drawer partial, included once per session inside the existing `x-data="totCard(…)"` scope. Task 6 adds the PIC strip which calls `open_(month)` on it.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TotTest.php`:

```php
public function test_the_board_no_longer_ships_the_centre_modal(): void
{
    TotSession::create([
        'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 3,
        'title' => 'Install git on our own server', 'status' => 'done',
    ]);

    $this->actingInTenant()->get('/app/tot?year=2026')
        ->assertDontSee('tot-modal-card', false)
        ->assertSee('wd-scrim', false);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=TotTest`

Expected: FAIL — `tot-modal-card` is still present and `wd-scrim` is absent.

- [ ] **Step 3: Add the drawer CSS deltas**

Append to `resources/css/app.css`:

```css
/* The PIC strip. Somebody who presents once a year does not need a route, they
   need the board to point at their month. */
.tot-pic { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0;
  padding:12px 20px; background:var(--red-tint); border-bottom:1px solid var(--hairline);
  font-size:13.5px; color:var(--ink); }
.tot-pic b { font-weight:600; }
.tot-pic-sub { color:var(--body); }
.tot-pic button { margin-left:auto; border:1px solid var(--red); background:var(--card);
  color:var(--red-active); border-radius:999px; padding:5px 13px;
  font:500 12.5px var(--font-sans); cursor:pointer; }
.tot-pic button:hover { border-color:var(--red-active); }

/* Standalone avatar. NOT .wa — that is the 20px overlapping stack chip with 9px
   initials, which would collide and import a known undersized-text problem. */
.tot-av-c { width:34px; height:34px; border-radius:50%; flex-shrink:0; display:inline-flex;
  align-items:center; justify-content:center; font:700 12px var(--font-sans); color:#fff; }

/* Authoring is a desk task. This is a policy line, not a fitting problem: the
   edit form sits inside the 560px drawer, so it is the same width on a phone and
   would fit. It is gated because setting a roster or writing up a topic is
   deliberate work — so it must never fail silently. Below 900px the button is
   replaced by a sentence, never simply removed. */
@media (max-width:899px) {
  .tot-authoring { display:none !important; }
  .tot-authoring-note { display:flex !important; }
}
.tot-authoring-note { display:none; }

@media (max-width:640px) {
  .tot-pic { padding:12px 16px; }
  .tot-pic button { margin-left:0; width:100%; }
  /* app.css gives .tot-act padding:0 and no height — a fine mouse target and a
     poor thumb one. The flyouts need nothing here: they already become a bottom
     sheet at 480px above. */
  .tot-act { min-height:40px; }
  .tot-sc { width:40px; height:40px; }
}
```

**Do not add a `.wd` mobile rule.** `app.css:893` already carries `@media (max-width:600px) { .wd { width:100vw; max-width:100vw } }` from the T.A.A. port. The spec text says "full width below 640px"; the shipped rule is 600px. Leave it at 600px — the difference is immaterial, and editing a rule the board drawer also uses to match a number invented for this spec is the exact mistake the Global Constraints warn about.

Then **delete** the `.tot-modal`, `.tot-modal-back`, `.tot-modal-card`, `.tot-modal-head`, `.tot-modal-body`, `.tot-modal-foot` rules, the `@keyframes tot-modal-in`, and the `.tot-modal-card` line inside the `prefers-reduced-motion` block. They are TOT-only: `app.css` defines them and `tot.blade.php` is the sole consumer.

- [ ] **Step 4: Create the drawer partial**

Create `resources/views/partials/tot-drawer.blade.php`. It expects `$session`, `$canEditSlot`, `$canManage`, `$canAssignPresenter`, `$isPresenterOfSlot`, `$canParticipate`, `$assignableEmployees`, `$statusLabels` and `$slotFailed` from the including scope, and sits inside the `totCard()` Alpine component.

```blade
{{-- Teleported so the drawer is never clipped by a row, and so only one is ever
     painted at a time. Built from the .wd-* classes the T.A.A. board shipped;
     do not add new .wd- rules. --}}
<template x-teleport="body">
    <div x-show="drawerOpen" x-cloak>
        <div class="wd-scrim" :data-open="drawerOpen ? '' : null" @click="drawerOpen = false"></div>
        <aside class="wd" :data-open="drawerOpen ? '' : null" role="dialog" aria-modal="true"
               @keydown.escape.window="flyout ? (flyout = null) : (drawerOpen = false)"
               :aria-label="$store.ui.lang==='en' ? @js($session->session_date->format('F Y')) : @js($session->session_date->format('F Y'))">

            <div class="wd-head">
                <span style="font:600 13.5px var(--font-sans);color:var(--ink);">{{ $session->session_date->format('F Y') }}</span>
                <button type="button" class="wd-ico" style="margin-left:auto;" @click="drawerOpen = false"
                        :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="wd-body">
                @php
                    $presenterName = $session->presenter?->name ?? $session->presenter_name;
                    $isEvent = in_array($session->status, ['not_tot', 'skipped'], true);
                @endphp

                @if (! $isEvent)
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                        @if ($presenterName)
                            <span class="tot-av-c" style="background:{{ '#' . substr(md5($presenterName), 0, 6) }};">{{ mb_strtoupper(mb_substr($presenterName, 0, 1)) }}</span>
                        @endif
                        <div>
                            <div style="font-size:15px;font-weight:600;color:var(--ink);">{{ $presenterName ?: 'Nobody assigned' }}</div>
                            <div class="wd-sub" style="margin:1px 0 0;">{{ $session->session_date->format('l j F Y') }}</div>
                        </div>
                        @if ($isPresenterOfSlot)
                            <span class="tot-presenter-tag" style="margin-left:auto;"
                                  x-text="$store.ui.lang==='en' ? 'You present' : 'Anda membentang'">You present</span>
                        @endif
                    </div>
                @else
                    <p class="wd-sub">{{ $session->session_date->format('l j F Y') }}</p>
                @endif

                <h2 class="wd-title @if (! filled($session->title)) wd-inline--empty @endif">{{ $session->title ?: 'No topic yet' }}</h2>

                @if (filled($session->description))
                    <p style="font-size:13.5px;color:var(--body);line-height:1.65;margin:0 0 18px;">{{ $session->description }}</p>
                @endif

                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
                    @forelse ($session->links ?? [] as $link)
                        <a class="tot-lk" href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">{{ $link['label'] }}</a>
                    @empty
                        <span class="tot-note" x-text="$store.ui.lang==='en' ? 'No material uploaded yet.' : 'Belum ada bahan dimuat naik.'">No material uploaded yet.</span>
                    @endforelse
                </div>

                @include('partials.tot-actions', ['session' => $session, 'canParticipate' => $canParticipate])

                @if ($canEditSlot)
                    <hr class="wd-rule">
                    @include('partials.tot-edit-form', [
                        'session' => $session, 'canManage' => $canManage,
                        'canAssignPresenter' => $canAssignPresenter,
                        'isPresenterOfSlot' => $isPresenterOfSlot,
                        'assignableEmployees' => $assignableEmployees,
                        'statusLabels' => $statusLabels, 'slotFailed' => $slotFailed,
                    ])
                @endif

                <hr class="wd-rule">

                {{-- Anonymous rater notes. Present only for a viewer the server decided may
                     see scores, which is the presenter and management. Never a name. --}}
                <template x-if="notes.length">
                    <div class="wd-locked" style="display:block;">
                        <div class="wd-sech" style="margin-bottom:6px;"
                             x-text="$store.ui.lang==='en' ? 'Anonymous notes from raters' : 'Nota tanpa nama daripada penilai'">Anonymous notes from raters</div>
                        <template x-for="(n, i) in notes" :key="i">
                            <div style="font-size:13.5px;color:var(--body);margin-bottom:4px;" x-text="n"></div>
                        </template>
                    </div>
                </template>

                <h3 class="wd-sech" x-text="comments ? ($store.ui.lang==='en' ? `Discussion · ${comments}` : `Perbincangan · ${comments}`) : ($store.ui.lang==='en' ? 'Discussion' : 'Perbincangan')">Discussion</h3>

                <template x-if="thread === null">
                    <div class="tot-note" x-text="$store.ui.lang==='en' ? 'Loading' : 'Memuatkan'">Loading</div>
                </template>
                <template x-if="thread !== null && thread.length === 0">
                    <div class="tot-note" x-text="$store.ui.lang==='en' ? 'No comments yet. Start the discussion.' : 'Belum ada komen. Mulakan perbincangan.'">No comments yet.</div>
                </template>

                <div class="wd-cmts">
                    <template x-for="c in (thread || [])" :key="c.id">
                        <div class="wd-cmt">
                            <span class="tot-av" :style="`background:${c.color};color:#fff;`" x-text="c.initials"></span>
                            <div style="min-width:0;flex:1;">
                                <div class="wd-cmt-who">
                                    <span class="wd-cmt-name" x-text="c.name"></span>
                                    <span class="tot-presenter-tag" x-show="c.presenter"
                                          x-text="$store.ui.lang==='en' ? 'Presenter' : 'Pembentang'">Presenter</span>
                                    <span class="wd-cmt-at" x-text="c.at"></span>
                                    <button type="button" x-show="c.canDelete" class="wd-ico" style="margin-left:auto;width:22px;height:22px;"
                                            @click="removeComment(c.id)"
                                            :aria-label="$store.ui.lang==='en' ? 'Remove comment' : 'Buang komen'">&times;</button>
                                </div>
                                <div class="wd-cmt-body" x-text="c.body"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            @if ($canParticipate && $session->status !== 'skipped')
                <div class="wd-foot">
                    <textarea rows="1" x-ref="composer" maxlength="2000"
                              :placeholder="$store.ui.lang==='en' ? 'Ask a question or add what you learned' : 'Tanya soalan atau kongsi apa yang anda pelajari'"
                              @keydown.enter.prevent="postComment($event.target.value); $event.target.value = ''"></textarea>
                    <button type="button" class="uj-btn-primary wd-post"
                            @click="postComment($refs.composer.value); $refs.composer.value = ''"
                            x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</button>
                </div>
            @endif
        </aside>
    </div>
</template>
```

- [ ] **Step 5: Extract the action row and the edit form**

Create `resources/views/partials/tot-actions.blade.php` by moving the existing `<div class="tot-actions">…</div>` block out of `tot.blade.php` unchanged, except: **delete the speech-bubble button entirely**. The thread is in the same panel now, so a button that scrolls to something already on screen is noise.

Create `resources/views/partials/tot-edit-form.blade.php` by moving the existing `@if ($canEditSlot)` block's contents out of `tot.blade.php` unchanged, with two edits:

1. Wrap the `Edit slot` button and the form in `<div class="tot-authoring">…</div>`.
2. Immediately after that div, add the note that replaces it on a narrow screen:

```blade
<div class="wd-locked tot-authoring-note">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
    <span x-text="$store.ui.lang==='en' ? 'Editing your slot needs a wider screen. Your topic, description and links are set from a laptop. Everything else here works on a phone.' : 'Menyunting slot anda memerlukan skrin lebih lebar. Topik, penerangan dan pautan ditetapkan dari komputer riba. Selebihnya di sini berfungsi pada telefon.'">Editing your slot needs a wider screen.</span>
</div>
```

- [ ] **Step 6: Point the row at the drawer**

In `resources/views/screens/tot.blade.php`:

1. Change the wrapper `x-data` from `{ open: …, editing: … }` to `{ editing: {{ $slotFailed ? 'true' : 'false' }} }` and move `totCard(…)` onto that same wrapper element so the drawer and the row share one component.
2. Change the row's `@click="open = !open"` to `@click="openDrawer()"` and drop `:aria-expanded`.
3. Replace the chevron path `M6 9l6 6 6-6` with `M9 18l6-6-6-6` and delete the `:style="open ? 'transform:rotate(180deg)' : ''"` binding — the row opens a panel now, it does not expand.
4. Delete the entire `<div class="tot-wrap">…</div>` element, including the `.tot-panel` inside it and the whole `.tot-modal` block.
5. Where the panel used to be, add `@include('partials.tot-drawer', […])`.
6. Delete `.tot-wrap` and `.tot-panel` from the moved CSS in `app.css` — with the accordion gone, so is the `overflow:visible` delay hack they existed for.

Keep the `@else` branch (the "no row yet" create form) exactly as it is; it is Task 8's concern, not this one.

- [ ] **Step 7: Rename the modal flag**

In `resources/js/tot-card.js`, rename `modalOpen` to `drawerOpen` and add an opener:

```js
        drawerOpen: false,

        openDrawer() {
            this.drawerOpen = true;

            return this.openThread();
        },
```

and change `openThread()`'s first line from `this.modalOpen = true;` to nothing — `openDrawer()` owns the flag now.

- [ ] **Step 8: Run the tests and build**

```bash
php artisan test --compact --filter=Tot
bun run build
```

Expected: all TOT tests PASS; build succeeds.

- [ ] **Step 9: Verify in the browser**

At `http://localhost:9100/app/tot`, click a month. The drawer should slide in from the right, Escape should close it, and the comment thread should load inside it. Resize below 900px: the `Edit slot` button should be replaced by the explanatory note, not vanish.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/partials/tot-drawer.blade.php resources/views/partials/tot-actions.blade.php resources/views/partials/tot-edit-form.blade.php resources/views/screens/tot.blade.php resources/css/app.css resources/js/tot-card.js tests/Feature/TotTest.php public/build
git commit -m "feat(tot): open a session in the side drawer, not an accordion

The board expanded a row in place and then opened a second, centre-modal
surface for comments. The T.A.A. board already settled on a right
slide-over for this job, so TOT adopts the same .wd-* drawer rather than
keeping a second grammar.

Deleting the accordion also removes the overflow:visible delay hack, which
existed only because the rating flyout opened upward past a clipping
ancestor. The speech bubble goes with it: the thread is in the panel now."
```

---

### Task 6: The PIC strip

**Files:**
- Modify: `app/Http/Controllers/TotController.php` (`screenData`)
- Modify: `resources/views/screens/tot.blade.php`
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: the drawer from Task 5.
- Produces: `screenData()` returns a `myMonth` key — a `TotSession` or `null`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TotTest.php`:

```php
public function test_the_board_points_a_presenter_at_their_own_month(): void
{
    TotSession::create([
        'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 2,
        'status' => 'planned', 'presenter_employee_id' => $this->employee->id,
    ]);

    $this->actingInTenant()->get('/app/tot?year=2026')
        ->assertSee('tot-pic', false)
        ->assertSee('You present in February');
}

public function test_the_board_shows_no_strip_to_somebody_who_presents_nothing(): void
{
    TotSession::create([
        'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 2,
        'status' => 'planned',
    ]);

    $this->actingInTenant()->get('/app/tot?year=2026')
        ->assertDontSee('tot-pic', false);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotTest`

Expected: FAIL — `tot-pic` is never rendered.

- [ ] **Step 3: Return the month**

In `app/Http/Controllers/TotController.php`, in `screenData()`, add to the returned array:

```php
            // The one slot this viewer presents in the displayed year, if any. A
            // person presents once a year, so they do not need their own route —
            // they need the board to point at their month.
            'myMonth' => $employee
                ? collect($sessions)->first(fn (TotSession $s) => $s->exists
                    && $s->presenter_employee_id === $employee->id)
                : null,
```

- [ ] **Step 4: Render the strip**

In `resources/views/screens/tot.blade.php`, directly after the closing `</div>` of `.tot-mast` and before `@if ($allUnassigned)`:

```blade
@if ($myMonth)
    @php
        $myMonthIndex = $myMonth->month - 1;
        $myTopic = filled($myMonth->title)
            ? $myMonth->title
            : 'No topic yet';
    @endphp
    <p class="tot-pic">
        <span>
            <b>You present in {{ $myMonth->session_date->format('F') }}.</b>
            <span class="tot-pic-sub">{{ $myTopic }} — {{ $myMonth->session_date->format('l j F Y') }}</span>
        </span>
        <button type="button" @click="$dispatch('tot-open', { month: {{ $myMonth->month }} })"
                x-text="$store.ui.lang==='en' ? 'Open my slot' : 'Buka slot saya'">Open my slot</button>
    </p>
@endif
```

Then, on the per-session wrapper element in the loop, listen for that event:

```blade
@window.tot-open="if ($event.detail.month === {{ $session->month }}) openDrawer()"
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`

Expected: PASS.

- [ ] **Step 6: Verify in the browser**

Sign in as `manager@amanahku.test`, whose seeded slot is in the current year. The strip should appear under the masthead and `Open my slot` should open that month's drawer.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php resources/views/screens/tot.blade.php tests/Feature/TotTest.php public/build
git commit -m "feat(tot): point a presenter at their own month

Somebody presenting in March had to remember it was March, find the row and
open it. Nothing on the board said so. A strip under the masthead now names
their month and opens it, which is what a per-PIC view would have been for
without needing a route."
```

---

### Task 7: Roster data and access control

**Files:**
- Modify: `app/Http/Controllers/TotController.php` (add `rosterData`)
- Modify: `app/Http/Controllers/AppController.php` (one match arm)
- Modify: `app/Support/Amanahku.php` (screen meta)
- Modify: `app/Support/Features.php` (module gating)
- Create: `resources/views/screens/tot-roster.blade.php` (placeholder in this task)
- Test: `tests/Feature/TotAssignPermissionTest.php`

**Interfaces:**
- Consumes: `canAssignPresenter()` and `assignableEmployees()`, both already private on `TotController`.
- Produces: `rosterData(Request $request, ?Employee $employee): array` returning `year` (int), `years` (int[]), `slots` (TotSession[12], some unsaved), `assignableEmployees` (Collection).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TotAssignPermissionTest.php`:

```php
public function test_a_holder_can_reach_the_roster_screen(): void
{
    $this->grantAssign();

    $this->actingAsManager()->get('/app/tot-roster')->assertOk();
}

public function test_a_manager_without_the_override_cannot_reach_the_roster(): void
{
    $this->actingAsManager()->get('/app/tot-roster')->assertForbidden();
}

public function test_the_roster_renders_twelve_slots_for_a_year_with_no_rows(): void
{
    $this->grantAssign();

    $response = $this->actingAsManager()->get('/app/tot-roster?year=2031');

    $response->assertOk()->assertSee('2031');
    $this->assertSame(0, TotSession::where('year', 2031)->count());
}

public function test_the_roster_is_gated_by_the_knowledge_module(): void
{
    $this->grantAssign();

    app(FeatureManager::class)->setTenant($this->tenant, 'module.knowledge', false);

    // AppController:169 aborts 404, not 403, for a screen whose module is off.
    // A disabled module should look absent, not forbidden.
    $this->actingAsManager()->get('/app/tot-roster')->assertNotFound();
}
```

Add `use App\Support\FeatureManager;` to the test file's imports.

This test also proves the `Features.php` edit below actually took: without adding `tot-roster` to the module's screen list, `moduleForScreen()` returns null, the screen counts as un-gated core, and the request would return 200.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: FAIL — `/app/tot-roster` has no match arm, so the screen 404s or renders empty.

- [ ] **Step 3: Add `rosterData`**

Add to `app/Http/Controllers/TotController.php`, next to `screenData()`:

```php
/**
 * The assignment picker. Twelve slots for any year, saved or not, because
 * session_date is computed rather than stored — a year needs no rows to exist.
 *
 * @return array<string, mixed>
 */
public function rosterData(Request $request, ?Employee $employee): array
{
    abort_unless($this->canAssignPresenter($request), 403);

    $year = (int) ($request->query('year') ?: now()->year);

    $saved = TotSession::with('presenter')->where('year', $year)->get()->keyBy('month');

    $slots = collect(range(1, 12))->map(fn (int $month) => $saved->get($month) ?? new TotSession([
        'year' => $year,
        'month' => $month,
        'status' => 'planned',
    ]))->all();

    return [
        'year' => $year,
        'years' => $this->availableYears($year),
        'slots' => $slots,
        'assignableEmployees' => $this->assignableEmployees(),
    ];
}
```

- [ ] **Step 4: Register the screen**

In `app/Http/Controllers/AppController.php`, beside the existing `'tot' =>` arm:

```php
            'tot-roster' => app(TotController::class)->rosterData($request, $employee),
```

In `app/Support/Amanahku.php`, beside the existing `'tot' =>` screen-meta entry:

```php
            'tot-roster' => ['title' => 'TOT Roster', 'title_ms' => 'Jadual TOT', 'sub' => 'Assign a presenter to each month. Click a person and the cursor moves on.', 'sub_ms' => 'Tetapkan pembentang untuk setiap bulan. Klik seseorang dan kursor bergerak ke bulan seterusnya.', 'crumb' => ['TOT Sessions', 'Roster']],
```

In `app/Support/Features.php`, extend the knowledge module's screen list:

```php
        'module.knowledge' => ['Knowledge Bank', ['knowledge-bank', 'tot', 'tot-roster'], 2],
```

**Add no nav entry.** `BuildsNav` gates by role, and a `tot.assign` holder is usually a `manager` — a `roles` allowlist would hide the screen from exactly the person the permission exists for. Task 9 links to it from the board instead.

- [ ] **Step 5: Create a placeholder view**

Create `resources/views/screens/tot-roster.blade.php`:

```blade
@extends('layouts.app')

@section('screen')
    <div class="uj-card" style="padding:20px;">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);margin:0;">{{ $year }}</h2>
    </div>
@endsection
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: PASS, all tests in the file.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php app/Http/Controllers/AppController.php app/Support/Amanahku.php app/Support/Features.php resources/views/screens/tot-roster.blade.php tests/Feature/TotAssignPermissionTest.php
git commit -m "feat(tot): add the roster screen behind tot.assign

Twelve slots for any year, including one with no rows at all, because
session_date is computed rather than stored. No nav entry: BuildsNav gates
by role and a tot.assign holder is usually a manager, so a roles allowlist
would hide the screen from the person the permission was created for."
```

---

### Task 8: The roster picker

**Files:**
- Modify: `resources/views/screens/tot-roster.blade.php` (replace the placeholder)
- Create: `resources/js/tot-roster.js`
- Modify: `resources/js/app.js` (register the component)
- Modify: `resources/css/app.css` (`.tr-*`)
- Test: `tests/Feature/TotAssignPermissionTest.php`

**Interfaces:**
- Consumes: `rosterData()` from Task 7; `POST /app/tot` and `POST /app/tot/{session}`, both already existing and already accepting a `tot.assign` holder.
- Produces: nothing downstream.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TotAssignPermissionTest.php`:

```php
public function test_a_holder_assigning_from_the_roster_creates_a_planned_slot(): void
{
    $this->grantAssign();

    $this->actingAsManager()->post('/app/tot', [
        'year' => 2026, 'month' => 5,
        'presenter_employee_id' => $this->employee->id,
    ])->assertRedirect();

    $session = TotSession::where('year', 2026)->where('month', 5)->first();

    $this->assertNotNull($session);
    $this->assertSame('planned', $session->status);
    $this->assertSame($this->employee->id, $session->presenter_employee_id);
}

public function test_the_roster_lists_every_assignable_person(): void
{
    $this->grantAssign();

    $this->actingAsManager()->get('/app/tot-roster?year=2026')
        ->assertOk()
        ->assertSee($this->employee->name);
}

public function test_assigning_over_an_existing_slot_changes_only_the_presenter(): void
{
    $this->grantAssign();

    $session = TotSession::create([
        'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 5,
        'title' => 'Barcode rollout', 'status' => 'done',
    ]);

    $this->actingAsManager()->post("/app/tot/{$session->id}", [
        'totform' => $session->id,
        'presenter_employee_id' => $this->employee->id,
    ])->assertRedirect();

    $session->refresh();

    $this->assertSame($this->employee->id, $session->presenter_employee_id);
    $this->assertSame('Barcode rollout', $session->title, 'the roster must not touch the material');
    $this->assertSame('done', $session->status, 'the roster must not touch the status');
}
```

The last two assertions are the point: `update()` only validates keys a holder is allowed to send, so the title and status survive a roster write even though the request omits them. If either changed, the rule set has regressed.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: the first test may already PASS (`store()` accepts holders since the previous spec). The second FAILS — the placeholder view lists nobody.

- [ ] **Step 3: Add the roster CSS**

Append to `resources/css/app.css`:

```css
/* ── TOT roster picker ─────────────────────────────────────────
   Twelve slots beside the roster, with a cursor that advances to the
   next empty month after each pick. Filling a year is twelve clicks;
   correcting one month is two.
   ─────────────────────────────────────────────────────────── */
.tr { display:grid; grid-template-columns:400px minmax(0,1fr); gap:22px; align-items:start; }
.tr-panel { background:var(--card); border:1px solid var(--hairline); border-radius:14px; padding:14px; }
.tr-slots { display:flex; flex-direction:column; }
.tr-slot { display:grid; grid-template-columns:26px 62px minmax(0,1fr) 26px; align-items:center; gap:10px;
  width:100%; text-align:left; background:none; border:0; border-top:1px solid var(--hairline-soft);
  padding:9px 6px; cursor:pointer; font-family:inherit; border-radius:9px; transition:background .13s, box-shadow .13s; }
.tr-slot:first-child { border-top:0; }
.tr-slot:hover { background:var(--hairline-soft); }
.tr-slot[data-cursor] { background:var(--red-tint); box-shadow:inset 0 0 0 1.5px var(--red); }
.tr-num { width:22px; height:22px; border-radius:50%; display:grid; place-items:center;
  font:600 11px var(--font-mono); background:var(--hairline-soft); color:var(--muted); }
.tr-slot[data-filled] .tr-num { background:var(--red); color:#fff; }
.tr-mon { font:600 12px var(--font-sans); letter-spacing:.1em; text-transform:uppercase; color:var(--muted); }
.tr-mon span { display:block; font:500 11px var(--font-mono); letter-spacing:0; text-transform:none; color:var(--muted); }
.tr-who { display:flex; align-items:center; gap:8px; min-width:0; }
.tr-who-n { font-size:13.5px; font-weight:600; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tr-empty { font-size:13px; color:var(--muted); border:1px dashed var(--hairline); border-radius:8px; padding:4px 10px; }
.tr-empty--event { color:var(--body); border-style:solid; background:var(--shelf); border-color:var(--shelf-line); }
.tr-x { width:24px; height:24px; border:0; background:none; border-radius:7px; cursor:pointer;
  color:var(--muted); display:grid; place-items:center; visibility:hidden; }
.tr-slot[data-filled] .tr-x { visibility:visible; }
.tr-x:hover { background:var(--hairline); color:var(--red); }
.tr-search { width:100%; height:36px; padding:0 12px; border:1px solid var(--hairline); border-radius:9px;
  font:inherit; font-size:13px; color:var(--ink); outline:none; margin-bottom:12px; background:#fff; }
.tr-search:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-tint); }
.tr-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(158px,1fr)); gap:8px; }
.tr-p { position:relative; display:flex; align-items:center; gap:9px; padding:9px 10px;
  border:1px solid var(--hairline); border-radius:11px; background:var(--card); cursor:pointer;
  font-family:inherit; text-align:left; min-width:0; transition:border-color .13s, box-shadow .13s; }
.tr-p:hover { border-color:var(--red); box-shadow:0 3px 10px rgba(31,30,26,.07); }
.tr-p-n { font-size:13px; font-weight:500; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tr-badges { position:absolute; top:-7px; right:-6px; display:flex; gap:3px; }
.tr-badge { min-width:19px; height:19px; padding:0 4px; border-radius:999px; background:var(--red); color:#fff;
  font:600 10.5px var(--font-mono); display:grid; place-items:center; box-shadow:0 0 0 2px var(--canvas); }
.tr-desk { display:none; background:var(--card); border:1px solid var(--hairline); border-radius:14px;
  padding:22px; text-align:center; }
.tr-desk h3 { margin:0 0 6px; font-size:15px; font-weight:600; color:var(--ink); }
.tr-desk p { margin:0; font-size:var(--t-sm); color:var(--body); line-height:1.6; }

/* The picker needs both columns visible at once for the cursor to make sense. */
@media (max-width:899px) {
  .tr { display:none; }
  .tr-desk { display:block; }
}
```

- [ ] **Step 4: Write the Alpine component**

Create `resources/js/tot-roster.js`:

```js
export function registerTotRoster(Alpine) {
    Alpine.data('totRoster', (seed) => ({
        ...seed,
        cursor: null,
        filter: '',
        busy: false,

        init() {
            this.cursor = this.nextEmpty(0);
        },

        /**
         * The next month with no presenter, wrapping at December. A not_tot month
         * is a deliberate "there is no TOT this month" marker and takes no
         * presenter, so the cursor steps over it. A skipped month stays open,
         * because correcting a month that was wrongly left empty should not need
         * HR to change its status first.
         */
        nextEmpty(from) {
            for (let i = 0; i < 12; i++) {
                const k = (from + i) % 12;
                if (!this.slots[k].presenter && this.slots[k].status !== 'not_tot') return k;
            }

            return null;
        },

        get people() {
            const q = this.filter.trim().toLowerCase();

            return q ? this.roster.filter((p) => p.name.toLowerCase().includes(q)) : this.roster;
        },

        badgesFor(id) {
            return this.slots
                .map((s, i) => (s.presenter && s.presenter.id === id ? i + 1 : null))
                .filter(Boolean);
        },

        setCursor(i) {
            if (this.slots[i].status === 'not_tot') return;
            this.cursor = i;
        },

        assign(person) {
            if (this.cursor === null || this.busy) return;

            const i = this.cursor;
            const previous = this.slots[i].presenter;

            this.slots[i].presenter = person;      // optimistic
            this.cursor = this.nextEmpty(i + 1);

            return this.write(i, person.id).catch(() => {
                this.slots[i].presenter = previous;
                this.cursor = i;
            });
        },

        clear(i) {
            if (this.busy) return;

            const previous = this.slots[i].presenter;
            this.slots[i].presenter = null;
            this.cursor = i;

            return this.write(i, '').catch(() => {
                this.slots[i].presenter = previous;
            });
        },

        /**
         * Every pick writes on the click. There is no bulk Save, because a
         * half-filled roster is a valid state and the person filling it stops
         * halfway more often than not.
         */
        async write(index, presenterId) {
            const slot = this.slots[index];
            const url = slot.id ? `/app/tot/${slot.id}` : '/app/tot';
            const body = slot.id
                ? { presenter_employee_id: presenterId, totform: slot.id }
                : { year: this.year, month: index + 1, presenter_employee_id: presenterId };

            this.busy = true;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(body),
                });
                if (!res.ok) throw new Error(res.status);
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'That did not save. Try again.'
                        : 'Tidak berjaya disimpan. Cuba lagi.'
                );
                throw e;
            } finally {
                this.busy = false;
            }
        },
    }));
}
```

Register it in `resources/js/app.js` beside `registerTotCard`:

```js
import { registerTotRoster } from './tot-roster';
// …
registerTotRoster(Alpine);
```

- [ ] **Step 5: Write the view**

Replace `resources/views/screens/tot-roster.blade.php` entirely:

```blade
@extends('layouts.app')

@php
    $slotSeed = collect($slots)->map(fn ($s) => [
        'id' => $s->exists ? $s->id : null,
        'status' => $s->status,
        'title' => $s->title,
        'date' => $s->session_date->format('j M'),
        'month' => $s->session_date->format('M'),
        'presenter' => $s->presenter_employee_id
            ? ['id' => $s->presenter_employee_id, 'name' => $s->presenter?->name ?? $s->presenter_name]
            : null,
    ])->values();
    $rosterSeed = $assignableEmployees->map(fn ($p) => ['id' => $p->id, 'name' => $p->display_name])->values();
@endphp

@section('screen')
<div class="tr-desk">
    <h3 x-text="$store.ui.lang==='en' ? 'Assigning presenters needs a wider screen' : 'Menetapkan pembentang memerlukan skrin lebih lebar'">Assigning presenters needs a wider screen</h3>
    <p x-text="$store.ui.lang==='en' ? 'The picker puts the twelve months beside the roster so a click lands in the month the cursor is on. That needs both columns at once. The board itself works here.' : 'Pemilih meletakkan dua belas bulan di sebelah senarai supaya klik jatuh pada bulan kursor. Ia perlukan kedua-dua lajur serentak. Papan TOT sendiri berfungsi di sini.'">The picker puts the twelve months beside the roster.</p>
</div>

<div class="tr" x-data="totRoster({ year: {{ $year }}, slots: {{ \Illuminate\Support\Js::from($slotSeed) }}, roster: {{ \Illuminate\Support\Js::from($rosterSeed) }} })">
    <div class="tr-panel">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
            <h4 class="wd-sech" style="margin:0;" x-text="$store.ui.lang==='en' ? 'Roster' : 'Jadual'">Roster</h4>
            <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
                @foreach ($years as $y)
                    <a href="{{ route('app.screen', ['screen' => 'tot-roster', 'year' => $y]) }}"
                       class="tot-yr" style="color:var(--muted);" @if ($y === $year) aria-selected="true" @endif>{{ $y }}</a>
                @endforeach
                {{-- session_date is computed, so a new year needs no rows: this is a link,
                     not a migration. The year sticks in the picker once somebody is assigned. --}}
                <a href="{{ route('app.screen', ['screen' => 'tot-roster', 'year' => max($years) + 1]) }}"
                   class="tot-pillbtn">+ {{ max($years) + 1 }}</a>
            </div>
        </div>

        <div class="tr-slots">
            <template x-for="(s, i) in slots" :key="i">
                <button type="button" class="tr-slot"
                        :data-filled="s.presenter ? '' : null"
                        :data-cursor="cursor === i ? '' : null"
                        @click="setCursor(i)">
                    <span class="tr-num" x-text="i + 1"></span>
                    <span class="tr-mon"><span x-text="s.month"></span><span x-text="s.date"></span></span>
                    <span class="tr-who">
                        <template x-if="s.presenter">
                            <span class="tr-who-n" x-text="s.presenter.name"></span>
                        </template>
                        <template x-if="!s.presenter && s.status === 'not_tot'">
                            <span class="tr-empty tr-empty--event" x-text="s.title"></span>
                        </template>
                        <template x-if="!s.presenter && s.status !== 'not_tot'">
                            <span class="tr-empty" x-text="$store.ui.lang==='en' ? 'Nobody yet' : 'Belum ada'">Nobody yet</span>
                        </template>
                    </span>
                    <span class="tr-x" role="button" @click.stop="clear(i)"
                          :aria-label="$store.ui.lang==='en' ? 'Clear this month' : 'Kosongkan bulan ini'">&times;</span>
                </button>
            </template>
        </div>
    </div>

    <div>
        <p class="tot-note" style="margin:0 0 12px;"
           x-text="cursor === null
             ? ($store.ui.lang==='en' ? 'Every month has a presenter. Click a month to change one.' : 'Setiap bulan sudah ada pembentang. Klik satu bulan untuk menukarnya.')
             : ($store.ui.lang==='en' ? `Cursor is on ${slots[cursor].month}. Click a person to assign them, and it moves to the next empty month.` : `Kursor pada ${slots[cursor].month}. Klik seseorang untuk menetapkannya, dan ia beralih ke bulan kosong seterusnya.`)"></p>

        <input class="tr-search" x-model="filter" autocomplete="off"
               :placeholder="$store.ui.lang==='en' ? 'Search the roster…' : 'Cari senarai…'">

        <div class="tr-grid">
            <template x-for="p in people" :key="p.id">
                <button type="button" class="tr-p" @click="assign(p)">
                    <span class="tr-p-n" x-text="p.name"></span>
                    <template x-if="badgesFor(p.id).length">
                        <span class="tr-badges">
                            <template x-for="b in badgesFor(p.id)" :key="b">
                                <span class="tr-badge" x-text="b"></span>
                            </template>
                        </span>
                    </template>
                </button>
            </template>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Run the tests and build**

```bash
php artisan test --compact --filter=Tot
bun run build
```

Expected: all PASS; build succeeds.

- [ ] **Step 7: Verify in the browser**

Sign in as HR, open `http://localhost:9100/app/tot-roster?year=2027`. Click three people in a row: they should land in January, February and March, take badges 1, 2 and 3, and the cursor should advance. Click the `×` on February: it should clear and the cursor should move there. Reload — the assignments should persist.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/tot-roster.blade.php resources/js/tot-roster.js resources/js/app.js resources/css/app.css tests/Feature/TotAssignPermissionTest.php public/build
git commit -m "feat(tot): fill a year from a click-per-month picker

Assigning twelve months meant twelve separate forms, each with a select and
a page reload, and there was no way to reach a year with no rows yet. A
cursor now sits on the next empty month and advances on each pick, so a
fresh year is twelve clicks and correcting one month is two.

Adding a year is a link, not a migration: session_date is computed and the
screen synthesises twelve slots for any year."
```

---

### Task 9: Link the roster from the board, and clean up

**Files:**
- Modify: `resources/views/screens/tot.blade.php`
- Delete: `public/_proto-tot.html`
- Test: `tests/Feature/TotAssignPermissionTest.php`

**Interfaces:**
- Consumes: `canAssignPresenter` from `screenData()`, already returned.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TotAssignPermissionTest.php`:

```php
public function test_the_board_offers_a_holder_the_roster_link(): void
{
    $this->grantAssign();

    $this->actingAsManager()->get('/app/tot')
        ->assertSee('tot-roster', false);
}

public function test_the_board_hides_the_roster_link_without_the_override(): void
{
    $this->actingAsManager()->get('/app/tot')
        ->assertDontSee('tot-roster', false);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: FAIL on the first — the board links nowhere.

- [ ] **Step 3: Add the link**

In `resources/views/screens/tot.blade.php`, inside `.tot-mast`, directly after the `.tot-yr-list` div:

```blade
@if ($canAssignPresenter)
    <a href="{{ route('app.screen', ['screen' => 'tot-roster', 'year' => $year]) }}"
       class="tot-yr" style="margin-top:8px;text-decoration:underline;text-underline-offset:3px;"
       x-text="$store.ui.lang==='en' ? 'Edit roster' : 'Sunting jadual'">Edit roster</a>
@endif
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=Tot`

Expected: PASS, every TOT test.

- [ ] **Step 5: Delete the prototype**

```bash
rm public/_proto-tot.html
```

It is gitignored (`.gitignore:49` matches `public/_*.html`), so this is housekeeping rather than a release gate — it stops the next person reading a stale mock as current.

- [ ] **Step 6: Full suite**

Run: `php artisan test --compact`

Expected: PASS. If anything unrelated fails, check whether the `.tot-modal*` deletion in Task 5 or the `app.css` move in Task 1 caught another screen, and report before fixing.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/tot.blade.php tests/Feature/TotAssignPermissionTest.php public/build
git commit -m "feat(tot): reach the roster from the board

The roster gets no sidebar entry because BuildsNav gates by role and a
tot.assign holder is usually a manager, so a roles allowlist would hide it
from the person the permission exists for. The masthead links to it when
canAssignPresenter is true, which keeps the check in the controller that
already owns it."
```

---

## Verification checklist

Run once at the end, at 1280px and 375px, on a **fresh page load at each width** — the in-app browser pane misreports computed styles right after a resize, and two phantom contrast failures during design were stale cascade reads.

- [ ] No console errors on the board or the roster
- [ ] The drawer traps focus; Escape closes it; Escape with a flyout open closes only the flyout
- [ ] Below 900px: the `Edit slot` button is replaced by the explanatory note, and the roster shows its card
- [ ] Below 640px: reactions, rating, watched and the composer all still work
- [ ] The roster cursor steps over a `not_tot` month
- [ ] `php artisan test --compact` is green
