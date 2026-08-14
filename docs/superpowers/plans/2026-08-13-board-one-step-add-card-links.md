# Board: one-step add card + card links — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse the board's two-step add-card flow into one click, and add a
`{label, url}` links list to every card.

**Architecture:** "+ Add a card" now creates the card immediately (same
`WorkItemController::store()` endpoint, unchanged) and opens the existing detail
drawer on it in the same motion — the drawer's per-field autosave becomes the
entry form, so the inline quick-add textarea is deleted outright. Links are a new
nullable `json` column on `work_items`, cast to `array`, validated and saved
through the drawer's existing per-field PATCH (`WorkItemController::update()`),
mirroring the `{label, url}` shape `TotSession::$links` already uses elsewhere in
this codebase.

**Tech Stack:** Laravel 13 / PHP 8.5, Blade + Alpine.js (no Livewire on this
screen), PHPUnit, MySQL (`json` column).

## Global Constraints

- PHP: explicit return types and param type hints on every touched method (project convention, `app/Http/Controllers/WorkItemController.php` already does this throughout).
- After any PHP file edit: run `vendor/bin/pint --dirty --format agent` before considering the task done.
- Every change must be programmatically tested; run the specific test file/filter after each change, not the whole suite, until the final task.
- No new files/tables beyond what's specified — `links` is a column on `work_items`, not a new model.
- Follow existing code precisely where a precedent exists: `TotSession::$links` / `TotController::isUntouchedLinkRow()` for the link shape and drop-blank-rows rule; `WorkItem::LABELS` / `toggleLabel()` for the "local mutate then commitField" client pattern.
- Full design reference: `docs/superpowers/specs/2026-08-13-board-one-step-add-card-links-design.md`.

---

### Task 1: `links` column + model cast

**Files:**
- Create: `database/migrations/2026_08_13_120000_add_links_to_work_items.php`
- Modify: `app/Models/WorkItem.php:40-43` (`casts()`)
- Test: `tests/Feature/BoardCardTest.php` (new test method)

**Interfaces:**
- Consumes: nothing new — `WorkItem` already has `protected $guarded = []`, so no fillable list to update.
- Produces: `WorkItem::$links` — `array<int, array{label: string, url: string}>|null`, readable/writable like any other cast attribute. Later tasks (`WorkItemController`, `work-board.js`) depend on this cast existing.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/BoardCardTest.php`, directly below `test_owner_sets_labels_and_real_due_date` (ends line 194):

```php
public function test_links_column_casts_to_array(): void
{
    $item = $this->card(['links' => [['label' => 'Doc', 'url' => 'https://example.com']]]);

    $this->assertSame([['label' => 'Doc', 'url' => 'https://example.com']], $item->fresh()->links);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_links_column_casts_to_array`
Expected: FAIL — `SQLSTATE... Unknown column 'links'` (column doesn't exist yet).

- [ ] **Step 3: Create the migration**

`database/migrations/2026_08_13_120000_add_links_to_work_items.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->json('links')->nullable(); // [{label, url}]
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('links');
        });
    }
};
```

- [ ] **Step 4: Add the cast**

In `app/Models/WorkItem.php`, change line 42 from:

```php
        return ['due_at' => 'date', 'assigned_at' => 'datetime', 'archived_at' => 'datetime', 'labels' => 'array'];
```

to:

```php
        return ['due_at' => 'date', 'assigned_at' => 'datetime', 'archived_at' => 'datetime', 'labels' => 'array', 'links' => 'array'];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=test_links_column_casts_to_array`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_13_120000_add_links_to_work_items.php app/Models/WorkItem.php tests/Feature/BoardCardTest.php
git commit -m "feat(board): add links column to work_items"
```

---

### Task 2: Server validation and save path for links

**Files:**
- Modify: `app/Http/Controllers/WorkItemController.php:165-198` (`update()`)
- Modify: `app/Http/Controllers/WorkItemController.php:594-623` (`cardPayload()`)
- Test: `tests/Feature/BoardCardTest.php` (three new test methods)

**Interfaces:**
- Consumes: `WorkItem::$links` cast from Task 1.
- Produces: `PATCH /app/board/{workItem}` accepts `links` in its body (array of `{label, url}`, max 12, blank rows silently dropped, partially-filled rows rejected); `cardPayload()['links']` — always present, `[]` when null, consumed by Task 3's client code as `card.links`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/BoardCardTest.php`, below the new `test_links_column_casts_to_array`:

```php
public function test_owner_sets_links(): void
{
    $item = $this->card();

    $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
        'title' => 'X', 'type' => 'task', 'priority' => 'low',
        'links' => [
            ['label' => 'Doc', 'url' => 'https://example.com/doc'],
            ['label' => 'Meet', 'url' => 'https://example.com/meet'],
        ],
    ])->assertOk()->assertJsonPath('card.links', [
        ['label' => 'Doc', 'url' => 'https://example.com/doc'],
        ['label' => 'Meet', 'url' => 'https://example.com/meet'],
    ]);

    $this->assertSame([
        ['label' => 'Doc', 'url' => 'https://example.com/doc'],
        ['label' => 'Meet', 'url' => 'https://example.com/meet'],
    ], $item->fresh()->links);
}

public function test_link_missing_url_is_rejected(): void
{
    $item = $this->card();

    $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
        'title' => 'X', 'type' => 'task', 'priority' => 'low',
        'links' => [['label' => 'Doc', 'url' => '']],
    ])->assertStatus(422)->assertJsonValidationErrors(['links.0.url']);
}

public function test_blank_link_row_is_dropped_not_rejected(): void
{
    $item = $this->card();

    $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
        'title' => 'X', 'type' => 'task', 'priority' => 'low',
        'links' => [
            ['label' => '', 'url' => ''],
            ['label' => 'Doc', 'url' => 'https://example.com/doc'],
        ],
    ])->assertOk()->assertJsonPath('card.links', [
        ['label' => 'Doc', 'url' => 'https://example.com/doc'],
    ]);
}

public function test_more_than_twelve_links_is_rejected(): void
{
    $item = $this->card();

    $links = array_map(fn ($n) => ['label' => "Link {$n}", 'url' => "https://example.com/{$n}"], range(1, 13));

    $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
        'title' => 'X', 'type' => 'task', 'priority' => 'low',
        'links' => $links,
    ])->assertStatus(422)->assertJsonValidationErrors(['links']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="test_owner_sets_links|test_link_missing_url_is_rejected|test_blank_link_row_is_dropped_not_rejected|test_more_than_twelve_links_is_rejected"`
Expected: FAIL — `links` isn't validated or saved yet, so the first test's `card.links` path won't match and the other two won't get a 422 (unknown fields are silently dropped by `validate()`, so `test_link_missing_url_is_rejected` fails by returning 200 instead of 422).

- [ ] **Step 3: Add the drop-blank-rows helper and wire it into `update()`**

In `app/Http/Controllers/WorkItemController.php`, add this private method directly below `update()` (after line 198, before `move()`):

```php
    /**
     * A link row nobody filled in: no label and no URL. Dropped on save rather
     * than rejected — mirrors TotController::isUntouchedLinkRow(), simplified
     * since WorkItem has no pre-seeded default labels to also check for.
     *
     * @param  array<string, mixed>  $link
     */
    private function isUntouchedLinkRow(array $link): bool
    {
        return blank($link['label'] ?? null) && blank($link['url'] ?? null);
    }
```

Then in `update()` (lines 165-198), drop untouched rows before validating and add
the `links` rules to the `sometimes` set:

```php
    public function update(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeManage($request, $workItem, $employee);

        // The drawer's link editor keeps a blank "+ Add a link" row in local state
        // until it's filled in — drop rows nobody touched before validating, same
        // rule TotController::store() uses for TOT session links.
        if (is_array($request->input('links'))) {
            $request->merge(['links' => array_values(array_filter(
                $request->input('links'),
                fn ($link) => is_array($link) && ! $this->isUntouchedLinkRow($link),
            ))]);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'required', 'in:assignment,task,adhoc'],
            'priority' => ['sometimes', 'required', 'in:high,medium,low'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'due_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'estimate_hours' => ['prohibited'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['string', Rule::in(array_keys(WorkItem::LABELS))],
            'links' => ['sometimes', 'array', 'max:12'],
            'links.*.label' => ['required_with:links', 'string', 'max:60'],
            'links.*.url' => ['required_with:links', 'url', 'max:2000'],
            'participant_ids' => ['sometimes', 'array'],
            'participant_ids.*' => ['integer'],
        ]);

        // Participants are a relation, not a column — pull them out before the fill.
        if (array_key_exists('participant_ids', $data)) {
            $this->syncParticipants($workItem, $data['participant_ids'], $employee);
            unset($data['participant_ids']);
        }

        $workItem->update($data);
        $workItem->load('participants');

        return response()->json([
            'card' => $this->cardPayload($workItem) + ['description' => $workItem->description],
            'html' => $this->cardHtml($workItem),
        ]);
    }
```

- [ ] **Step 4: Add `links` to `cardPayload()`**

In `app/Http/Controllers/WorkItemController.php:594-623`, add one line after the
`labels` entry (currently line 604):

```php
            'labels' => $item->labels ?? [],
            'links' => $item->links ?? [],
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="test_owner_sets_links|test_link_missing_url_is_rejected|test_blank_link_row_is_dropped_not_rejected|test_more_than_twelve_links_is_rejected"`
Expected: PASS

- [ ] **Step 6: Run the full `BoardCardTest` file to check for regressions**

Run: `php artisan test --compact tests/Feature/BoardCardTest.php`
Expected: PASS (all tests, including Task 1's)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/WorkItemController.php tests/Feature/BoardCardTest.php
git commit -m "feat(board): validate and save links on card update"
```

---

### Task 3: Links UI in the card drawer

**Files:**
- Modify: `resources/js/work-board.js:26-30` (`FIELD_DERIVED` — read only, confirm no entry needed)
- Modify: `resources/js/work-board.js:323-337` (`commitFieldFromCard`)
- Modify: `resources/js/work-board.js:346-353` (add new methods after `toggleLabel`)
- Modify: `resources/js/work-board.js:478-486` (`openCardCore`'s `drawer.card` assignment)
- Modify: `resources/views/screens/board.blade.php` (new "Links" section after Description, before line 310's `<hr class="wd-rule">`)

**Interfaces:**
- Consumes: `card.links` from `cardPayload()` (Task 2); `commitField(field, value)` (existing, `work-board.js:295-313`); `scheduleCommit(field)` (existing, `work-board.js:318-321`).
- Produces: `drawer.card.links` — always an array in client state; `addLink()`, `removeLink(idx)`, `onLinkInput()` — new Alpine methods called from the Blade template.

- [ ] **Step 1: Default `links` in `openCardCore`**

In `resources/js/work-board.js`, in `openCardCore` (around line 478-486), change:

```js
                this.drawer.card = {
                    ...card,
                    description: card.description ?? '',
                    due_at: card.due_at ?? '',
                    labels: card.labels ?? [],
                    participants: card.participants ?? [],
                    mentionable: card.mentionable ?? [],
                    project_id: card.project?.id ?? '',
                };
```

to:

```js
                this.drawer.card = {
                    ...card,
                    description: card.description ?? '',
                    due_at: card.due_at ?? '',
                    labels: card.labels ?? [],
                    links: card.links ?? [],
                    participants: card.participants ?? [],
                    mentionable: card.mentionable ?? [],
                    project_id: card.project?.id ?? '',
                };
```

- [ ] **Step 2: Add `addLink`, `removeLink`, `onLinkInput`**

In `resources/js/work-board.js`, directly after `toggleLabel` (ends line 353):

```js
        addLink() {
            if (this.drawer.locked) return;
            this.drawer.card.links.push({ label: '', url: '' });
        },

        removeLink(idx) {
            if (this.drawer.locked) return;
            this.drawer.card.links.splice(idx, 1);
            this.commitField('links', this.drawer.card.links);
        },

        onLinkInput() {
            this.scheduleCommit('links');
        },
```

- [ ] **Step 3: Add the `links` branch to `commitFieldFromCard`**

In `resources/js/work-board.js`, `commitFieldFromCard` (lines 323-337), add a
branch alongside the existing `title`/`description` ones:

```js
        commitFieldFromCard(field) {
            clearTimeout(this.drawer._timers[field]);
            if (field === 'title') {
                const val = (this.drawer.card.title || '').trim();
                if (!val) {
                    this.drawer.error = this.t('Title cannot be empty.', 'Tajuk tidak boleh kosong.');
                    return;
                }
                this.commitField('title', val);
                return;
            }
            if (field === 'description') {
                this.commitField('description', this.drawer.card.description || null);
                return;
            }
            if (field === 'links') {
                // Send a filtered copy so the server never rejects an untouched
                // blank row — but leave drawer.card.links itself alone, so a
                // half-typed row (label filled, url not yet) doesn't disappear
                // from the screen mid-edit.
                const filled = this.drawer.card.links.filter((l) => l.label.trim() || l.url.trim());
                this.commitField('links', filled);
            }
        },
```

- [ ] **Step 4: Add the Links section to the drawer template**

In `resources/views/screens/board.blade.php`, insert directly after the
Description `<textarea>` block (after line 308) and before `<hr class="wd-rule">`
(line 310):

```blade
                        <h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Links' : 'Pautan'">Links</h3>
                        <template x-if="!drawer.locked">
                            <div>
                                <template x-for="(link, idx) in drawer.card.links" :key="idx">
                                    <div style="display:grid;grid-template-columns:140px 1fr 30px;gap:8px;margin-bottom:8px;">
                                        <input class="wd-inline" style="margin:0;" x-model="link.label" @input="onLinkInput()" @blur="commitFieldFromCard('links')" placeholder="Label" maxlength="60">
                                        <input class="wd-inline" style="margin:0;" x-model="link.url" @input="onLinkInput()" @blur="commitFieldFromCard('links')" placeholder="https://...">
                                        <button type="button" @click="removeLink(idx)" style="border:0;background:none;color:var(--muted);font-size:14px;cursor:pointer;">&times;</button>
                                    </div>
                                </template>
                                <button type="button" class="wd-add" @click="addLink()">
                                    <span x-text="$store.ui.lang==='en' ? '+ Add a link' : '+ Tambah pautan'"></span>
                                </button>
                            </div>
                        </template>
                        <template x-if="drawer.locked">
                            <div class="wd-chiprow">
                                <template x-for="link in drawer.card.links" :key="link.url">
                                    <a :href="link.url" target="_blank" rel="noopener noreferrer" class="wd-inline" x-text="link.label"></a>
                                </template>
                                <template x-if="!drawer.card.links.length">
                                    <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'"></span>
                                </template>
                            </div>
                        </template>
```

- [ ] **Step 5: Build assets**

```bash
bun run build
```

- [ ] **Step 6: Manually verify in the browser**

Open `http://localhost:9100`, quick-login as any role, go to the board, open an
existing card.

- Click "+ Add a link" twice, fill in two `{label, url}` pairs, click elsewhere to
  blur. Reload the page, reopen the same card: both links are still there.
- Click a link's `×`: it disappears immediately (no reload needed).
- Add a link with only a label, no URL, and blur: an error appears
  (`drawer.error`, "Save failed...") and the row stays as typed, not silently
  dropped.
- Add a link, then remove all its text back to blank, blur: no error, and
  reloading the card shows the row gone (it was dropped, not saved as blank).
- Open a card as a participant (locked/read-only) that has links set: they render
  as clickable buttons opening in a new tab, not editable inputs.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/work-board.js resources/views/screens/board.blade.php public/build
git commit -m "feat(board): add links section to the card drawer"
```

---

### Task 4: One-step add card

**Files:**
- Modify: `resources/js/work-board.js:794-825` (delete `toggleComposer`/`submitAdd`, add `addCard`)
- Modify: `resources/views/screens/board.blade.php:119-140` (button + delete composer markup)
- Test: `tests/Feature/BoardCardTest.php` (existing `test_inline_add_returns_card_json`, run for regression — no new server test needed since `store()` itself doesn't change)

**Interfaces:**
- Consumes: `openCardCore(id, node)` (existing, `work-board.js:460-521`); `POST /app/board` (existing, unchanged, `WorkItemController::store()`).
- Produces: nothing new consumed by later tasks — this is the last task.

- [ ] **Step 1: Confirm the regression baseline passes first**

Run: `php artisan test --compact --filter=test_inline_add_returns_card_json`
Expected: PASS (this confirms `store()` needs no change before touching the client that calls it)

- [ ] **Step 2: Replace `toggleComposer`/`submitAdd` with `addCard`**

In `resources/js/work-board.js`, delete `toggleComposer` and `submitAdd` (lines
794-825) and replace both with:

```js
        async addCard(status) {
            if (this.busy) return;
            this.busy = true;
            try {
                // Same type-from-active-filter rule the old inline composer used, so
                // a card added while viewing one type of work stays visible under
                // that filter.
                const type = ['task', 'assignment', 'adhoc'].includes(this.filter) ? this.filter : 'assignment';
                const title = this.t('Untitled card', 'Kad tanpa tajuk');
                const { card, html } = await this.api('/app/board', {
                    method: 'POST',
                    body: JSON.stringify({ title, status, type, priority: 'medium' }),
                });
                const list = this.$root.querySelector(`[data-list="${status}"]`);
                const empty = list.querySelector('[data-empty]');
                if (empty) empty.remove();
                list.insertAdjacentHTML('beforeend', html);
                this.playEnter(list.lastElementChild);
                this.applyFilter();
                await this.openCardCore(String(card.id), list.lastElementChild);
            } finally {
                this.busy = false;
            }
        },
```

Also remove the now-unused `draft` and `open` state entries and any other
references to `toggleComposer`/`submitAdd` in the file (search the file for both
names to confirm nothing else calls them before deleting their declarations).

- [ ] **Step 3: Wire the button and delete the composer markup**

In `resources/views/screens/board.blade.php`, replace lines 119-140:

```blade
                @if ($employee)
                    <div style="margin-top:10px;">
                        <button type="button" x-show="!open['{{ $key }}']" @click="toggleComposer('{{ $key }}')"
                                style="width:100%;text-align:left;padding:9px 12px;border:1px dashed var(--hairline);border-radius:10px;background:transparent;font-size:12.5px;font-weight:500;color:var(--muted);cursor:pointer;">
                            <span x-text="$store.ui.lang==='en' ? '+ Add a card' : '+ Tambah kad'"></span>
                        </button>
                        <div x-show="open['{{ $key }}']" x-cloak class="uj-card" style="padding:10px;">
                            <textarea x-ref="draft_{{ $key }}" x-model="draft['{{ $key }}']"
                                      @keydown.enter.prevent="submitAdd('{{ $key }}')"
                                      @keydown.escape="toggleComposer('{{ $key }}')"
                                      rows="2" maxlength="160"
                                      :placeholder="$store.ui.lang==='en' ? 'What needs doing?' : 'Apa yang perlu dibuat?'"
                                      style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;font-size:13px;color:var(--ink);outline:none;resize:vertical;font-family:inherit;"></textarea>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <button type="button" @click="submitAdd('{{ $key }}')" :disabled="busy" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;">
                                    <span x-text="$store.ui.lang==='en' ? 'Add card' : 'Tambah'"></span>
                                </button>
                                <button type="button" @click="toggleComposer('{{ $key }}')" style="height:34px;padding:0 10px;font-size:12.5px;color:var(--muted);background:transparent;cursor:pointer;">×</button>
                            </div>
                        </div>
                    </div>
                @endif
```

with:

```blade
                @if ($employee)
                    <div style="margin-top:10px;">
                        <button type="button" :disabled="busy" @click="addCard('{{ $key }}')"
                                style="width:100%;text-align:left;padding:9px 12px;border:1px dashed var(--hairline);border-radius:10px;background:transparent;font-size:12.5px;font-weight:500;color:var(--muted);cursor:pointer;">
                            <span x-text="$store.ui.lang==='en' ? '+ Add a card' : '+ Tambah kad'"></span>
                        </button>
                    </div>
                @endif
```

- [ ] **Step 4: Build assets**

```bash
bun run build
```

- [ ] **Step 5: Manually verify in the browser**

Open `http://localhost:9100`, quick-login, go to the board.

- Click "+ Add a card" in any column: the card appears in that column titled
  "Untitled card" AND the detail drawer opens on it in the same action — no
  intermediate textarea.
- Type a real title in the drawer, click away: title autosaves (existing drawer
  behaviour), and the card face in the column updates to match.
- Close the drawer without typing anything: the "Untitled card" card is still
  there in the column.
- Switch the board filter to "Task" (or another single type) before adding a
  card: the new card is created with that type, same as the old composer did.

- [ ] **Step 6: Run the regression test again**

Run: `php artisan test --compact --filter=test_inline_add_returns_card_json`
Expected: PASS (unchanged — confirms the server side is untouched)

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/work-board.js resources/views/screens/board.blade.php public/build
git commit -m "feat(board): collapse add-card into one step, straight to the drawer"
```

---

### Task 5: Full verification pass

**Files:** none (verification only)

**Interfaces:** none — this task checks the previous four, it produces nothing.

- [ ] **Step 1: Run the full board test file**

Run: `php artisan test --compact tests/Feature/BoardCardTest.php`
Expected: PASS, all tests including the five added across Tasks 1-2.

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS. If anything outside `BoardCardTest` fails, stop and investigate
before proceeding — Tasks 1-4 only touch `WorkItem`, `WorkItemController`,
`work-board.js` and `board.blade.php`, so an unrelated failure signals a missed
side effect (e.g. another feature reading `cardPayload()`'s exact key set).

- [ ] **Step 3: Run the dev database migration**

Tests run against a separate database; the column must also exist on the one the
running app actually uses.

```bash
lerd artisan migrate
```

- [ ] **Step 4: Final manual walkthrough**

Repeat Task 3 Step 6 and Task 4 Step 5 in one pass against the freshly migrated
dev database: add a card in one step, add two links to it, reload, confirm both
persisted, confirm a bad link is rejected with a visible error.

- [ ] **Step 5: Confirm Pint is clean**

```bash
vendor/bin/pint --format agent
```

Expected: no further changes reported (Tasks 1, 2, 3, 4 already ran `--dirty`
after each edit).
