# Team-board kanban window Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat task list in the team-board screen's per-person floating window with a read-only, 4-column kanban that reuses the personal board's own card partial.

**Architecture:** No new query — `teamBoardData()` already returns every item across every visible employee. `work-card.blade.php` gains two optional data attributes (`data-owner-id`, `data-priority`) so `team-board.js`'s existing client-side filtering can read owner/priority off it the way it read them off the row partial it replaces. `team-board.blade.php` groups the same `$teamRows` collection into 4 status buckets and renders `work-card` inside each, stacked vertically (the window is 560px wide; 4 side-by-side kanban columns don't fit). The status-chip filter is removed since status is now the column itself. `team-board-row.blade.php` is deleted — its only caller is the code being replaced.

**Tech Stack:** Laravel 13 Blade views, Alpine.js (`resources/js/team-board.js`), PHPUnit 12 feature tests.

> **Revised mid-implementation** (after Tasks 1-3 below were built and committed as written): the shell changed from the `.wd` right-anchored slide-over (forcing the vertical stack described above and in Task 4) to a centered floating popup (`.tb-win-modal`) with the 4 columns laid out horizontally, matching `board.blade.php`'s own kanban shape. Task 4, below, was executed with this revision already in mind rather than as originally written — see the spec's "Revision: floating popup, horizontal columns" section for the exact diff. Tasks 1-3's file/interface contracts are unaffected.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-08-07-team-board-kanban-window-design.md`
- Read-only: no drag-and-drop, no add-card, no write path in this window — unchanged from today.
- Person-table (search/Overdue/Blocked/sort/click-to-open) is untouched.
- `data-owner-id` on `work-card.blade.php` is optional and must not render when no `$owner` is passed (the personal board never passes one).
- After every PHP file touched, run `vendor/bin/pint --dirty --format agent` before the task's final commit.
- Run tests with `php artisan test --compact --filter=<name>` per task; run the full `TeamBoardScreenTest` and `BoardCardTest` files at the end.

---

### Task 1: `work-card.blade.php` — optional owner/priority data attributes

**Files:**
- Modify: `resources/views/partials/work-card.blade.php:43-55` (the opening `<div class="wc ...">` tag)
- Test: `tests/Feature/BoardCardTest.php`

**Interfaces:**
- Consumes: nothing new — `$c` (WorkItem) and `$compact` are already parameters of this partial.
- Produces: an optional Blade variable `$owner` (array with at least an `id` key) that, when passed, renders `data-owner-id="{id}"` on the card's root `<div>`. `data-priority="{{ $c->priority }}"` renders unconditionally. Task 3 passes `$owner` from team-board.blade.php; the personal board (`screens/board.blade.php`) never passes it, so the attribute must not appear there.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/BoardCardTest.php` (follow the existing `$this->card()` / `actingInTenant()` helpers already used by `test_board_emits_project_data_attribute_for_filtering` in that file):

```php
    public function test_board_card_emits_priority_data_attribute(): void
    {
        $this->card(['priority' => 'high', 'title' => 'Priority card']);

        $this->actingInTenant()->get('/app/board')->assertOk()
            ->assertSee('data-priority="high"', false);
    }

    public function test_board_card_never_emits_owner_id_on_the_personal_board(): void
    {
        $this->card(['title' => 'No owner here']);

        $res = $this->actingInTenant()->get('/app/board')->assertOk();
        $this->assertStringNotContainsString('data-owner-id=', $res->getContent());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=test_board_card_emits_priority_data_attribute`
Expected: FAIL (no `data-priority` attribute yet). `test_board_card_never_emits_owner_id_on_the_personal_board` passes trivially today (attribute doesn't exist yet) — that's fine, it exists to catch a regression once Step 3 lands.

- [ ] **Step 3: Add the attributes**

In `resources/views/partials/work-card.blade.php`, change the opening tag (currently lines 43-55):

```blade
<div class="wc @if ($wcCompact) wc--sm @endif"
     data-card
     data-id="{{ $c->id }}"
     data-status="{{ $c->status }}"
     data-type="{{ $c->type }}"
     data-priority="{{ $c->priority }}"
     data-labels="{{ implode(',', $c->labels ?? []) }}"
     data-project="{{ $c->project_id }}"
     @if ($owner ?? null) data-owner-id="{{ $owner['id'] }}" @endif
     @if ($c->assigned_by_id) data-assigned="1" @endif
     {{-- Keyboard path to the drawer. Only on the personal board: team-board renders
          this same partial read-only with no click handler at all, so making those
          copies focusable would tab-stop on something Enter/Space does nothing to. --}}
     @unless ($wcCompact) tabindex="0" role="button" aria-haspopup="dialog" @endunless
>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=test_board_card_emits_priority_data_attribute`
Run: `php artisan test --compact --filter=test_board_card_never_emits_owner_id_on_the_personal_board`
Expected: both PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/partials/work-card.blade.php tests/Feature/BoardCardTest.php
git commit -m "feat(board): emit priority and optional owner data attributes on cards

Team-board's per-person window (next commit) needs to filter cards by
owner and priority the same way the row partial it replaces did."
```

---

### Task 2: `team-board.blade.php` — kanban markup in the window

**Files:**
- Modify: `resources/views/screens/team-board.blade.php:195-234`
- Delete: `resources/views/partials/team-board-row.blade.php`
- Test: `tests/Feature/TeamBoardScreenTest.php`

**Interfaces:**
- Consumes: `partials/work-card.blade.php` with `['c' => WorkItem, 'compact' => true, 'owner' => ['id' => int]]` (Task 1's new attributes).
- Produces: the window's task area keeps the `x-ref="winTaskBody"` anchor Task 3's JS queries, but now wraps 4 status-grouped sections instead of one flat list. Every `[data-id]` card still carries `data-owner-id`, `data-type`, `data-priority`, `data-project`, `data-labels` for Task 3's `applyWinFilter()` to read.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TeamBoardScreenTest.php` (same fixture helpers as the existing `test_every_task_is_present_in_the_markup`):

```php
    public function test_every_task_is_present_in_the_markup(): void
    {
        $alice = $this->makeEmployee('Alice');
        $this->makeCard($alice, ['title' => 'Card one']);
        $this->makeCard($alice, ['title' => 'Card two']);
        $this->makeCard($this->managerEmployee, ['title' => 'Boss card']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $teamRows = $response->viewData('teamRows');
        $this->assertGreaterThan(0, $teamRows->count());

        $html = $response->getContent();

        foreach ($teamRows as $row) {
            $this->assertStringContainsString('data-id="'.$row['item']->id.'"', $html);
            $this->assertStringContainsString('data-owner-id="'.$row['owner_id'].'"', $html);
        }

        // Exactly one card per teamRows entry.
        $this->assertSame($teamRows->count(), substr_count($html, 'data-owner-id="'));
    }

    /**
     * Grouping happens by status — a card assigned to the "review" column
     * must not also render (or get miscounted) under another column.
     */
    public function test_window_groups_cards_by_status(): void
    {
        $alice = $this->makeEmployee('Alice');
        $reviewCard = $this->makeCard($alice, ['title' => 'In review card', 'status' => 'review']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/data-id="'.$reviewCard->id.'"\s+data-status="review"/',
            $html
        );
    }
```

Replace the body of the existing `test_every_task_is_present_in_the_markup` (it currently asserts `data-card-id="..."`, defined at the top of this file around line 108-129) with the version above — same method name, updated assertions.

`test_guide_copy_no_longer_mentions_lanes`, already in the file, is unrelated to this change and stays as-is.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=TeamBoardScreenTest`
Expected: FAIL — `data-owner-id` and grouped `data-status="review"` adjacency don't exist yet (old markup still uses `data-card-id` and `.tb-tline`).

- [ ] **Step 3: Replace the window's task markup**

In `resources/views/screens/team-board.blade.php`, remove the status-chip loop (currently lines 221-225):

```blade
                    @foreach (['todo' => ['To Do', 'To Do'], 'prog' => ['In Progress', 'Sedang Jalan'], 'review' => ['In Review', 'Disemak'], 'done' => ['Done', 'Selesai']] as $sk => $sl)
                        <button type="button" class="tb-chip" @click="toggleWinStatus('{{ $sk }}')" :data-on="win.statusFilter.includes('{{ $sk }}') ? '' : null">
                            <span x-text="$store.ui.lang==='en' ? @js($sl[0]) : @js($sl[1])">{{ $sl[0] }}</span>
                        </button>
                    @endforeach
```

Then replace the task list block (currently lines 228-233):

```blade
                <div x-ref="winTaskBody">
                    @forelse ($teamRows as $row)
                        @include('partials.team-board-row', ['row' => $row])
                    @empty
                    @endforelse
                </div>
```

with:

```blade
                @php
                    $tbWinCols = ['todo' => ['To Do', 'To Do'], 'prog' => ['In Progress', 'Sedang Jalan'], 'review' => ['In Review', 'Disemak'], 'done' => ['Done', 'Selesai']];
                    $tbRowsByStatus = $teamRows->groupBy(fn ($row) => $row['item']->status);
                @endphp
                <div class="tb-win-kanban" x-ref="winTaskBody">
                    @foreach ($tbWinCols as $sk => $sl)
                        <div class="tb-win-col">
                            <div class="tb-win-col-head">
                                <span x-text="$store.ui.lang==='en' ? @js($sl[0]) : @js($sl[1])">{{ $sl[0] }}</span>
                            </div>
                            <div class="tb-win-col-cards">
                                @foreach ($tbRowsByStatus->get($sk, collect()) as $row)
                                    @include('partials.work-card', ['c' => $row['item'], 'compact' => true, 'owner' => ['id' => $row['owner_id']]])
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
```

Leave the block immediately after (the `@if ($teamRows->isNotEmpty()) ... winVisibleCount === 0 ... @endif` empty-state) untouched — it already reasons about `$teamRows`, not the row partial.

Delete `resources/views/partials/team-board-row.blade.php` (no longer included anywhere).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=TeamBoardScreenTest`
Expected: PASS (all methods in the file, including the two new/updated ones)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/team-board.blade.php tests/Feature/TeamBoardScreenTest.php
git rm resources/views/partials/team-board-row.blade.php
git commit -m "feat(team-board): render the per-person window as a 4-column kanban

Groups \$teamRows by status and renders each bucket with the personal
board's own work-card partial instead of the flat team-board-row list.
Drops the status-chip filter — status is now the column itself."
```

---

### Task 3: `team-board.js` — filter cards instead of rows

**Files:**
- Modify: `resources/js/team-board.js:56-62` (win state), `:189-193` (openWindow reset), `:242-247` (toggleWinStatus), `:261-281` (applyWinFilter)

**Interfaces:**
- Consumes: `[data-id]` cards (Task 1/2's output) carrying `data-owner-id`, `data-type`, `data-priority`, `data-project`, `data-labels`.
- Produces: `applyWinFilter()` keeps its existing external contract (`winVisibleCount`, called from `openWindow()`, `toggleWinLabelFilter` etc.) — only its internal selector and matching logic change. `toggleWinStatus()` and `win.statusFilter` no longer exist; nothing else in this codebase calls them (confirmed via grep in the design's investigation).

- [ ] **Step 1: Write the failing test**

This is browser-side JS with no existing unit-test harness in this codebase (the project tests Blade/PHP via PHPUnit only, per `docs/RULES.md` conventions). Verification happens by having Task 2's PHPUnit tests exercise the markup this JS depends on, plus a manual browser check in Step 4 below. Skip to Step 2 — there is no separate failing-test step for this task.

- [ ] **Step 2: Remove the status filter and update the selector**

In `resources/js/team-board.js`, remove `statusFilter: ['todo', 'prog', 'review'],` from the `win` object literal (currently line 60):

```javascript
        win: {
            show: false,
            open: false,
            person: null,
            trigger: null,
            typeFilter: '',
            priorityFilter: '',
            projectFilter: '',
            labelFilter: null,
            _closeTimer: null,
        },
```

Remove the reset line in `openWindow()` (currently `this.win.statusFilter = ['todo', 'prog', 'review'];`, line 193):

```javascript
        openWindow(row) {
            const id = Number(row.dataset.personId);
            const person = this.people.find((p) => p.id === id);
            if (!person) return;

            this.win.trigger = row;
            this.win.person = person;
            this.win.typeFilter = '';
            this.win.priorityFilter = '';
            this.win.projectFilter = '';
            this.win.labelFilter = null;
            this.applyWinFilter();
```

Remove `toggleWinStatus()` entirely (currently lines 242-247):

```javascript
        setWinLabelFilter(key) {
            this.win.labelFilter = this.win.labelFilter === key ? null : key;
            this.applyWinFilter();
        },
```

(this leaves `setWinLabelFilter` as the method directly following `openWindow`'s callers, with `toggleWinStatus` gone from between them)

Update `applyWinFilter()` (currently lines 261-281) to select `[data-id]` instead of `[data-card-id]` and drop the status check:

```javascript
        applyWinFilter() {
            const body = this.$refs.winTaskBody;
            if (!body || !this.win.person) {
                this.winVisibleCount = 0;
                return;
            }
            const ownerId = String(this.win.person.id);
            let visible = 0;
            body.querySelectorAll('[data-id]').forEach((row) => {
                const labels = (row.dataset.labels || '').split(',').filter(Boolean);
                const matches = row.dataset.ownerId === ownerId
                    && (!this.win.typeFilter || row.dataset.type === this.win.typeFilter)
                    && (!this.win.priorityFilter || row.dataset.priority === this.win.priorityFilter)
                    && (!this.win.projectFilter || row.dataset.project === this.win.projectFilter)
                    && (!this.win.labelFilter || labels.includes(this.win.labelFilter));
                row.style.display = matches ? '' : 'none';
                if (matches) visible += 1;
            });
            this.winVisibleCount = visible;
        },
```

- [ ] **Step 3: Rebuild frontend assets**

Run: `bun run build`

- [ ] **Step 4: Manual browser verification**

Run: `lerd artisan db:seed --class=DevLoginSeeder` (only if `/dev/login` 404s — the worktree shares the parent's database, so seeded accounts likely already exist).

Open `http://localhost:9100/dev/login?email=manager@amanahku.test&tenant=unijaya`, navigate to the team-board screen, click a person with cards in more than one status. Confirm: 4 stacked column headings appear (To Do / In Progress / In Review / Done), cards render under the correct heading, cards for *other* people are not visible, and the Type/Priority/Project/Label filters still narrow the visible cards. Confirm no status-filter chips remain.

(Note: per project memory, this worktree may not be reachable via the numeric port `9100`, which serves the main checkout — check `worktree-team-board-kanban-window.amanahku.localhost` per this repo's `CLAUDE.md` "Git worktrees" section instead, or fall back to the PHPUnit coverage from Tasks 1-2 if neither resolves.)

- [ ] **Step 5: Run the full relevant test suite**

Run: `php artisan test --compact tests/Feature/TeamBoardScreenTest.php tests/Feature/BoardCardTest.php tests/Feature/TeamBoardDataTest.php tests/Feature/TeamBoardAccessTest.php tests/Feature/AllScreensRenderTest.php`
Expected: all PASS

- [ ] **Step 6: Commit**

```bash
git add resources/js/team-board.js public/build
git commit -m "feat(team-board): filter kanban cards by owner instead of list rows

applyWinFilter() now reads data-id/data-owner-id (work-card.blade.php)
instead of data-card-id (the deleted team-board-row partial), and no
longer filters by status now that status is the column layout itself."
```

---

### Task 4: CSS for the stacked kanban columns

**Files:**
- Modify: `resources/css/app.css:2285-2334`

**Interfaces:**
- Consumes: `.tb-win-kanban`, `.tb-win-col`, `.tb-win-col-head`, `.tb-win-col-cards` class names from Task 2's Blade markup.
- Produces: nothing consumed by later tasks (this is the last task).

- [ ] **Step 1: Remove the now-dead `.tb-pill` and `.tb-tline*` rules**

`.tb-pill` (used only by the deleted `team-board-row.blade.php`'s status pill) and every `.tb-tline*` rule (that partial's own shell) are now unused — confirmed via `grep -rn "tb-pill\|tb-tline" resources/views resources/js` returning no remaining Blade/JS references after Task 2/3. Delete lines 2285-2289 (`.tb-pill` block, currently between `.tb-clear` and `.tb-empty`):

```css
.tb-pill { display: inline-flex; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.tb-pill[data-status="todo"] { background: var(--hairline-soft); color: var(--muted); }
.tb-pill[data-status="prog"] { background: #e9f0f7; color: var(--info); }
.tb-pill[data-status="review"] { background: #faf1e2; color: var(--amber); }
.tb-pill[data-status="done"] { background: #e7f4ee; color: var(--success-ink); }
```

And delete lines 2321-2334 (the `.tb-tline*` block and its header comment, currently between `.tb-win-filters` and the `/* ── TOT year lineup ── */` comment):

```css
/* ── Task line, inside the floating window (partials/team-board-row.blade.php) ──
   One task per person's window, stacked (not a grid column) — the window is
   always narrower than the old table ever needed to be, and there is no
   Person column to align against anymore. Reuses .wc-type/.wc-dot,
   .wc-when/.wc-sep/.wc-proj and .wc-labels/.wc-label from the card face
   above; only the shell and the priority text are new here. */
.tb-tline { padding: 11px 0; border-top: 1px solid var(--hairline-soft); }
.tb-tline:first-of-type { border-top: 0; }
.tb-tline-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
.tb-tline-title { font-size: var(--t-base); font-weight: 600; color: var(--ink); line-height: 1.4; margin: 0 0 6px; text-wrap: pretty; }
.tb-tline-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: var(--t-micro); color: var(--muted); margin-bottom: 6px; }
.tb-tline-pri { font-weight: 600; }
.tb-tline-pri[data-priority="high"] { color: var(--error); }
```

- [ ] **Step 2: Add the kanban column styles**

In the gap left by Step 1 (immediately after `.tb-win-filters`, before the `/* ── TOT year lineup ── */` comment), add:

```css
/* ── Per-person kanban, inside the floating window (screens/team-board.blade.php) ──
   4 status columns stacked vertically, not side-by-side — the window is
   560px wide (.wd), well under the ~1090px four 272px-min columns would
   need. Cards reuse partials/work-card.blade.php (.wc/.wc--sm) wholesale;
   the only new rules are the column shell and an override for .wc's
   cursor: pointer, since these cards aren't clickable. */
.tb-win-kanban { display: flex; flex-direction: column; gap: 20px; }
.tb-win-col-head { font-size: var(--t-micro); font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
.tb-win-col-cards { display: flex; flex-direction: column; gap: 8px; }
.tb-win-kanban .wc { cursor: default; }
```

- [ ] **Step 3: Verify no other file references the removed classes**

Run: `grep -rn "tb-pill\|tb-tline" resources/views resources/js`
Expected: no output (both partials/JS that used them are already gone from Tasks 2-3).

- [ ] **Step 4: Run the full relevant test suite one more time**

Run: `php artisan test --compact tests/Feature/TeamBoardScreenTest.php tests/Feature/BoardCardTest.php`
Expected: all PASS (CSS doesn't affect PHPUnit, but this confirms nothing else broke since Task 3)

- [ ] **Step 5: Rebuild assets and commit**

```bash
bun run build
git add resources/css/app.css public/build
git commit -m "style(team-board): style the stacked kanban columns, drop dead .tb-tline/.tb-pill rules

.tb-tline* and .tb-pill only ever styled the now-deleted
team-board-row.blade.php partial."
```

---

## Final verification

- [ ] **Run the full test suite**

Run: `php artisan test --compact`
Expected: all PASS, 0 failures

- [ ] **Confirm no dead references remain**

Run: `grep -rn "team-board-row\|data-card-id\|toggleWinStatus\|statusFilter" resources/ tests/`
Expected: no output
